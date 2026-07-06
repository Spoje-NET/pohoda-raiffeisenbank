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
 * themselves are still valid; to build the modern Entra ID v2 app-only
 * context (see buildEntraIdContext()) that replaces the retired Azure
 * ACS flow; and to retry an operation once on a transient auth failure
 * (see withSharePointRetry()).
 *
 * Microsoft fully retired SharePoint "App-Only via Azure ACS" on
 * 2026-04-02 for all tenants, with no extension possible (see
 * https://learn.microsoft.com/sharepoint/dev/sp-add-ins/retirement-announcement-for-azure-acs).
 * Confirmed against the daramis tenant: the ACS token endpoint still
 * issues a syntactically valid token (HTTP 200), but SharePoint Online
 * rejects it on the actual REST call with HTTP 401
 * {"error":"invalid_request"} - this is not tenant-specific
 * (Get-SPOTenant DisableCustomAppAuthentication was confirmed False on
 * daramis) nor a credential/secret problem, it is Microsoft's
 * infrastructure no longer honoring ACS-issued tokens at all.
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
     * Build a SharePoint ClientContext authenticated via the modern Entra ID
     * v2 app-only client_credentials flow (replaces the retired ACS
     * ClientCredential/withCredentials() path for the client-id/secret case).
     *
     * The app registration behind OFFICE365_CLIENTID/OFFICE365_CLSECRET must
     * have the Microsoft Graph "Sites.Selected" application permission,
     * admin-consented, and be granted access to the target site via
     * `POST /sites/{siteId}/permissions` - a token here does not by itself
     * guarantee the REST call below will succeed if that grant is missing.
     */
    public static function buildEntraIdContext(string $tenant, string $site, string $clientId, string $clientSecret): \Office365\SharePoint\ClientContext
    {
        $authCtx = new EntraIdAppOnlyAuthenticationContext(
            $tenant,
            $clientId,
            $clientSecret,
            'https://'.$tenant.'.sharepoint.com/.default',
        );

        return new \Office365\SharePoint\ClientContext('https://'.$tenant.'.sharepoint.com/sites/'.$site, $authCtx);
    }

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
     * Independently re-acquire an Entra ID v2 app-only token using the same
     * OFFICE365_* config as the failing call, to tell apart "the
     * credentials/tenant/app registration are broken" from "auth is fine,
     * something else failed" (e.g. the app has no Sites.Selected grant on
     * this site - see class docblock).
     *
     * Uses EntraIdAppOnlyAuthenticationContext directly rather than the SDK,
     * so the raw HTTP status/body are visible regardless of what the SDK's
     * own exception happened to capture. Result is memoized per process -
     * one extra probe per run is enough to diagnose, repeating it per failed
     * file would just add load on Microsoft's endpoint.
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
        $authCtx = new EntraIdAppOnlyAuthenticationContext(
            $tenant,
            Shared::cfg('OFFICE365_CLIENTID'),
            Shared::cfg('OFFICE365_CLSECRET'),
            'https://'.$tenant.'.sharepoint.com/.default',
        );

        try {
            $authCtx->authenticateRequest(new \Office365\Runtime\Http\RequestOptions('https://'.$tenant.'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')));

            return self::$office365AuthProbe = 'auth probe: independent Entra ID v2 token request succeeded (HTTP 200) - '
                .'credentials/tenant/app registration are valid; if the SharePoint call above still failed with 401/403, '
                .'the app is missing the Microsoft Graph "Sites.Selected" grant on this site';
        } catch (\Office365\Runtime\Http\RequestException $exc) {
            return self::$office365AuthProbe = \sprintf(
                'auth probe: independent Entra ID v2 token request FAILED (HTTP %d): %s',
                $exc->getCode(),
                $exc->getMessage(),
            );
        }
    }

    /**
     * Run a SharePoint ClientContext operation, retrying once on a transient
     * auth failure.
     *
     * $resetAuth is called before the retry to discard whatever cached
     * token/credential state caused the failure, forcing a fresh one on the
     * next attempt - e.g. EntraIdAppOnlyAuthenticationContext::forceRefresh()
     * for the modern flow. This matters because, at least for the legacy ACS
     * flow this replaces, a plain ClientRuntimeContext::executeQueryRetry()
     * does *not* help: \Office365\Runtime\Auth\ACSTokenProvider hands the
     * decoded response body straight to AuthenticationContext as the access
     * token without checking the HTTP status, so a transient hiccup caches a
     * broken "token" that a bare resend would just resubmit unchanged.
     *
     * @param \Office365\SharePoint\ClientContext $ctx          context the operation acts through
     * @param callable                            $resetAuth    called before each retry to force a fresh auth attempt
     * @param callable                            $operation    receives $ctx, performs the SharePoint call(s) and executeQuery(), and returns the result
     * @param int                                 $maxAttempts  total attempts including the first, before giving up
     * @param int                                 $delaySeconds seconds to wait before a retry, to give the auth service a moment to recover
     *
     * @return mixed whatever $operation returned
     */
    public static function withSharePointRetry(
        \Office365\SharePoint\ClientContext $ctx,
        callable $resetAuth,
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
                $resetAuth();
            }
        }
    }
}
