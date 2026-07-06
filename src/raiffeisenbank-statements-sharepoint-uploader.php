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
use Office365\Runtime\Auth\UserCredentials;
use Office365\SharePoint\ClientContext;

\define('APP_NAME', 'RaiffStatementUpload');

require_once '../vendor/autoload.php';

/**
 * Get today's Statements list.
 */
$options = \getopt('o::e::', ['output::environment::']);
Shared::init(
    [
        'OFFICE365_SITE', 'OFFICE365_PATH',
        'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$certValid = PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));

if (!\defined('FIXED_RATE')) {
    \define('FIXED_RATE', 1);
}

$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'), ['user' => '', 'password' => '', 'ico' => '', 'url' => '']);
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));

if (Shared::cfg('ACCOUNT_CURRENCY', false)) {
    $engine->setCurrency(Shared::cfg('ACCOUNT_CURRENCY'));
}

if (Shared::cfg('STATEMENT_LINE')) {
    $engine->setStatementLine(Shared::cfg('STATEMENT_LINE'));
}

if (Shared::cfg('APP_DEBUG', false)) {
    $engine->logBanner(Shared::AppName().' '.Shared::AppVersion(), ' '.$engine->getAccount().' '.$engine->getCurrencyCode().' Scope: '.$engine->scope.' LINE: '.$engine->statementLine);
}

$exitcode = $certValid ? 0 : 1;
$fileUrls = [];
$report = [
    'account' => $engine->getAccount(),
    'currency' => $engine->getCurrencyCode(),
    'scope' => $engine->scope,
    'until' => $engine->getUntil()->format('Y-m-d'),
    'since' => $engine->getSince()->format('Y-m-d'),
    'certificate_valid' => $certValid,
    'missing' => [],
    'existing' => [],
    'download_errors' => [],
];

if (!$certValid) {
    $engine->addStatusMessage(_('Certificate validation failed. Skipping statement processing.'), 'error');
    $report['error'] = 'Invalid certificate';
} else {
    try {
        $pdfStatements = $engine->getStatementFilenames('pdf');

        if ($pdfStatements) {
            if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
                // Legacy user credential flow, untouched by the ACS retirement -
                // still goes through classic SharePoint REST (_api/web/...).
                $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
                $engine->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME').' and OFFICE365_PASSWORD', 'debug');
                $ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
                $resetAuth = static function () use ($ctx, $credentials): void {
                    $ctx->withCredentials($credentials);
                };
                $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));
                $engine->addStatusMessage('ServiceRootUrl: '.$ctx->getServiceRootUrl(), 'debug');

                $doList = static function () use ($ctx, $resetAuth, $targetFolder): array {
                    $sharepointFilesRaw = PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($targetFolder) {
                        return $targetFolder->getFiles()->get()->executeQuery();
                    });
                    $sharepointFiles = [];

                    // @phpstan-ignore foreach.nonIterable
                    foreach ($sharepointFilesRaw as $fileInSharepint) {
                        $sharepointFiles[$fileInSharepint->getName()] = $fileInSharepint->getServerRelativeUrl();
                    }

                    return $sharepointFiles;
                };
                $doUpload = static function (string $uploadAs, string $contents) use ($ctx, $resetAuth, $targetFolder): string {
                    return PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function ($ctx) use ($targetFolder, $uploadAs, $contents) {
                        $uploadFile = $targetFolder->uploadFile(basename($uploadAs), $contents);
                        $ctx->executeQuery();

                        return $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($uploadFile->getServerRelativeUrl());
                    });
                };
            } else {
                // Client-id/secret (app-only) case goes through Microsoft
                // Graph, not classic SharePoint REST - see
                // PohodaBankClientOffice's class docblock for why
                // (client-secret tokens are unconditionally rejected by
                // _api/web/... regardless of permissions granted,
                // "Unsupported app only token.").
                $engine->addStatusMessage('Using Microsoft Graph API (Entra ID v2 app-only) with OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID'), 'debug');
                $graph = PohodaBankClientOffice::buildGraphClient(
                    Shared::cfg('OFFICE365_TENANT'),
                    Shared::cfg('OFFICE365_SITE'),
                    Shared::cfg('OFFICE365_CLIENTID'),
                    Shared::cfg('OFFICE365_CLSECRET'),
                );
                $path = Shared::cfg('OFFICE365_PATH');

                $doList = static function () use ($graph, $path): array {
                    return $graph->listFiles($path);
                };
                $doUpload = static function (string $uploadAs, string $contents) use ($graph, $path): string {
                    return (string) $graph->uploadFile($path, basename($uploadAs), $contents)['webUrl'];
                };
            }

            try {
                $sharepointFiles = $doList();

                foreach ($pdfStatements as $pdfStatement) {
                    $uploadAs = Statementor::statementFilename($pdfStatement);

                    if (\array_key_exists($uploadAs, $sharepointFiles)) {
                        $engine->addStatusMessage(sprintf('File %s exists in SharePoint', $uploadAs), 'success');
                        $report['existing'][] = $uploadAs;
                    } else {
                        $engine->addStatusMessage(sprintf('File %s does not exist in SharePoint', $uploadAs), 'warning');

                        try {
                            preg_match('/\d{4}-\d{2}-\d{2}/', $uploadAs, $dateMatches);
                            $engine->setScope($dateMatches[0]);

                            try {
                                $downloadedPdf = $engine->downloadPDF();
                            } catch (\VitexSoftware\Raiffeisenbank\ApiException $downloadExc) {
                                $engine->addStatusMessage(sprintf('Failed to download PDF for %s: %s', $uploadAs, $downloadExc->getMessage()), 'error');
                                $report['download_errors'][$uploadAs] = $downloadExc->getMessage();

                                continue;
                            } catch (\Exception $downloadExc) {
                                $engine->addStatusMessage(sprintf('Failed to download PDF for %s: %s', $uploadAs, $downloadExc->getMessage()), 'error');
                                $report['download_errors'][$uploadAs] = $downloadExc->getMessage();

                                continue;
                            }

                            if (empty($downloadedPdf) || !\is_array($downloadedPdf)) {
                                $errorMsg = 'PDF download returned empty result or invalid format';
                                $engine->addStatusMessage(sprintf('Failed to download PDF for %s: %s', $uploadAs, $errorMsg), 'error');
                                $report['download_errors'][$uploadAs] = $errorMsg;

                                continue;
                            }

                            $pdfFilePath = current($downloadedPdf);

                            if ($pdfFilePath === false || !\is_string($pdfFilePath) || !file_exists($pdfFilePath)) {
                                $errorMsg = 'Invalid PDF file path returned or file does not exist';
                                $engine->addStatusMessage(sprintf('Failed to process PDF for %s: %s', $uploadAs, $errorMsg), 'error');
                                $report['download_errors'][$uploadAs] = $errorMsg;

                                continue;
                            }

                            try {
                                $uploaded = $doUpload($uploadAs, file_get_contents($pdfFilePath));
                                $engine->addStatusMessage(_('Uploaded').': '.$uploaded, 'success');
                                $report['sharepoint'][basename($pdfStatement)] = $uploaded;
                                $fileUrls[basename($pdfStatement)] = $uploaded;
                            } catch (\Exception $exc) {
                                $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint upload of '.$uploadAs);
                                $engine->addStatusMessage($errorMessage, 'error');
                                $report['sharepoint_errors'][$uploadAs] = $errorMessage;

                                $exitcode = 1;
                            }

                            $report['missing'][] = $pdfStatement;
                        } catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
                            $report['mesage'] = $exc->getMessage();

                            $exitcode = $exc->getCode();

                            if (!$exitcode) {
                                if (preg_match('/cURL error ([0-9]*):/', $report['mesage'], $codeRaw)) {
                                    $exitcode = (int) $codeRaw[1];
                                }
                            }
                        }
                    }
                }
            } catch (\Office365\Runtime\Http\RequestException|GraphApiException $exc) {
                $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint file listing');
                $report['message'] = $errorMessage;
                $engine->addStatusMessage($errorMessage, 'error');
                $exitcode = $exc->getCode();
            }
        }
    } catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
        $report['mesage'] = $exc->getMessage();
        $engine->addStatusMessage($report['mesage'], 'error');
        $exitcode = $exc->getCode();
        $report['exitcode'] = $exitcode;

        if (!$exitcode) {
            if (preg_match('/cURL error ([0-9]*):/', $report['message'], $codeRaw)) {
                $exitcode = (int) $codeRaw[1];
            }
        }
    }
}

$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
