<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaRaiffeisenbank package
 *
 * https://github.com/Spoje-NET/pohoda-raiffeisenbank
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pohoda\RaiffeisenBank;

use Ease\Shared;

/**
 * Office365/SharePoint-specific diagnostics and resilience for the bank
 * statement upload flow.
 *
 * Used by pohodaSQL-raiffeisenbank-statements-sharepoint.php and
 * raiffeisenbank-statements-sharepoint-uploader.php to turn a bare
 * \Office365\Runtime\Http\RequestException into a report line that says
 * which file/operation failed, what Microsoft actually returned, and
 * (via probeOffice365Auth()) whether the OFFICE365_* credentials
 * themselves are still valid; and to retry an operation once when a
 * transient Microsoft ACS failure poisons the SDK's cached token
 * (see withSharePointRetry()).
 *
 * @author vitex
 */
abstract class PohodaBankClientOffice extends PohodaBankClient
{
    /**
     * Memoized result of probeOffice365Auth() - one probe per process is enough.
     */
    private static ?string $office365AuthProbe = null;

    /**
     * Build a diagnostic message for a failed remote request (e.g. SharePoint upload).
     *
     * Bare exception messages from office365/php-sdk are often just a terse
     * OAuth2 error code (e.g. "invalid_request") with no indication of which
     * file/operation failed or what the actual server-side reason was.
     * \Office365\Runtime\Http\RequestException carries the HTTP status and
     * raw response body (which usually holds Microsoft's error_description) -
     * surface both here so the log line alone is enough to diagnose the
     * failure without production access.
     *
     * @param string $context short description of what was being attempted, e.g. "PDF upload of foo.pdf"
     */
    public static function describeRequestException(\Exception $exc, string $context): string
    {
        $detail = $exc->getMessage();
        $source = 'error';

        if ($exc instanceof \Office365\Runtime\Http\RequestException) {
            // Response is Microsoft's own OAuth2/SharePoint REST error JSON, not
            // Pohoda mServer's - label it explicitly so it isn't mistaken for one.
            $source = 'Office365/SharePoint API error';

            if ($exc->getResponseBody()) {
                $detail .= ' | response: '.$exc->getResponseBody();
            }

            // The SDK exception alone often can't tell us whether the token
            // acquisition itself failed (bad/expired secret, retired ACS
            // realm) or whether auth succeeded and the failure is specific to
            // the REST call that follows it (e.g. missing folder/permission).
            // Re-run the token exchange independently, outside the SDK, so the
            // report says which one it was without needing production access.
            $detail .= ' | '.self::probeOffice365Auth();
        }

        return sprintf('%s: %s (HTTP %d): %s', $context, $source, $exc->getCode(), $detail);
    }

    /**
     * Independently re-acquire an Office365/SharePoint ACS app-only token using
     * the same OFFICE365_* config as the failing call, to tell apart "the
     * credentials/tenant are broken" from "auth is fine, something else failed".
     *
     * Performs the exact two requests \Office365\Runtime\Auth\ACSTokenProvider
     * does (realm discovery via WWW-Authenticate, then the ACS client_credentials
     * grant), but via a plain curl call so the raw HTTP status/body are visible
     * regardless of what the SDK's own exception happened to capture. Result is
     * memoized per process - one extra probe per run is enough to diagnose,
     * repeating it per failed file would just add load on Microsoft's endpoint.
     *
     * The client secret is used to make the request but is never included in
     * the returned diagnostic string.
     */
    public static function probeOffice365Auth(): string
    {
        if (self::$office365AuthProbe !== null) {
            return self::$office365AuthProbe;
        }

        $tenant = Shared::cfg('OFFICE365_TENANT');
        $site = Shared::cfg('OFFICE365_SITE');
        $clientId = Shared::cfg('OFFICE365_CLIENTID');
        $clientSecret = Shared::cfg('OFFICE365_CLSECRET');
        $siteUrl = 'https://'.$tenant.'.sharepoint.com/sites/'.$site;

        $headerLines = [];
        $ch = curl_init($siteUrl);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer'],
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headerLines) {
                $headerLines[] = $line;

                return \strlen($line);
            },
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $realmProbeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $realmCurlError = curl_error($ch);
        curl_close($ch);

        $realm = null;

        foreach ($headerLines as $line) {
            if (stripos($line, 'WWW-Authenticate:') === 0 && preg_match('/realm="([^"]+)"/', $line, $m)) {
                $realm = $m[1];

                break;
            }
        }

        if ($realm === null) {
            return self::$office365AuthProbe = \sprintf(
                'auth probe: could not discover ACS realm from %s (HTTP %d%s) - check OFFICE365_TENANT/OFFICE365_SITE',
                $siteUrl,
                $realmProbeCode,
                $realmCurlError !== '' ? ", curl error: {$realmCurlError}" : '',
            );
        }

        $resource = "00000003-0000-0ff1-ce00-000000000000/{$tenant}.sharepoint.com@{$realm}";
        $ch = curl_init("https://accounts.accesscontrol.windows.net/{$realm}/tokens/OAuth/2");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => "{$clientId}@{$realm}",
                'client_secret' => $clientSecret,
                'resource' => $resource,
                'scope' => $resource,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $tokenResponse = curl_exec($ch);
        $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tokenCurlError = curl_error($ch);
        curl_close($ch);

        $decoded = \is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;

        if ($tokenHttpCode === 200 && isset($decoded['access_token'])) {
            return self::$office365AuthProbe = \sprintf(
                'auth probe: independent ACS token request for realm %s succeeded (HTTP 200) - '
                .'credentials/tenant are valid, the failure is specific to the SharePoint REST call, not auth',
                $realm,
            );
        }

        return self::$office365AuthProbe = \sprintf(
            'auth probe: independent ACS token request for realm %s FAILED (HTTP %d): %s%s',
            $realm,
            $tokenHttpCode,
            $decoded['error'] ?? ($tokenResponse ?: '(empty response)'),
            $tokenCurlError !== '' ? ", curl error: {$tokenCurlError}" : '',
        );
    }

    /**
     * Run a SharePoint ClientContext operation, retrying once if a transient
     * Microsoft ACS failure leaves the SDK's cached auth token broken.
     *
     * \Office365\Runtime\Auth\ACSTokenProvider posts the client_credentials
     * grant and hands the *decoded response body* straight to
     * AuthenticationContext as the access token, without checking the HTTP
     * status - if ACS itself has a transient hiccup, the "token" ends up
     * being e.g. {"error":"invalid_request"} instead of a real one.
     * AuthenticationContext::authenticateRequest() then only re-acquires a
     * token when it is null, so once this happens every further request on
     * the same ClientContext - not just the one that tripped over it - keeps
     * sending the same broken token and failing identically. A plain
     * ClientRuntimeContext::executeQueryRetry() doesn't help here: it resends
     * the same request without touching the cached token.
     *
     * Calling ClientContext::withCredentials() again installs a fresh
     * AuthenticationContext (token reset to null), forcing a brand new ACS
     * token request on the next call, while $ctx and any ClientObject
     * (folder, file, ...) built from it keep working unchanged - they only
     * hold a reference to $ctx, not a copy.
     *
     * @param \Office365\SharePoint\ClientContext $ctx         context the operation acts through; reset in place on retry
     * @param \Office365\Runtime\Auth\ClientCredential|\Office365\Runtime\Auth\UserCredentials $credentials credentials to reinstall on $ctx before retrying
     * @param callable                             $operation   receives $ctx, performs the SharePoint call(s) and executeQuery(), and returns the result
     * @param int                                   $maxAttempts total attempts including the first, before giving up
     * @param int                                   $delaySeconds seconds to wait before a retry, to give ACS a moment to recover
     *
     * @return mixed whatever $operation returned
     */
    public static function withSharePointRetry(
        \Office365\SharePoint\ClientContext $ctx,
        $credentials,
        callable $operation,
        int $maxAttempts = 2,
        int $delaySeconds = 2,
    ) {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $operation($ctx);
            } catch (\Office365\Runtime\Http\RequestException $exc) {
                if ($attempt >= $maxAttempts) {
                    throw $exc;
                }

                sleep($delaySeconds);
                $ctx->withCredentials($credentials);
            }
        }
    }
}
