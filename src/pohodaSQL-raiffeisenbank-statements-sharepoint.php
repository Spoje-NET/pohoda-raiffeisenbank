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
use Office365\Runtime\Auth\ClientCredential;
use Office365\Runtime\Auth\UserCredentials;
use Office365\SharePoint\ClientContext;

require_once '../vendor/autoload.php';

\define('APP_NAME', 'Pohoda RaiffeisenBank Statements');

/**
 * Get today's Statements list.
 */
$options = getopt('o::e::', ['output::environment::']);
Shared::init(
    [
        'OFFICE365_TENANT', 'OFFICE365_PATH', 'OFFICE365_SITE',
        'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
        'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ],
    array_key_exists('environment', $options) ? $options['environment'] : (array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = array_key_exists('o', $options) ? $options['o'] : (array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$certValid = PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));
$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));

if (Shared::cfg('STATEMENT_LINE')) {
    $engine->setStatementLine(Shared::cfg('STATEMENT_LINE'));
}

if (Shared::cfg('APP_DEBUG', false)) {
    $engine->logBanner($engine->getAccount().' '.$engine->getCurrencyCode(), ' Scope: '.$engine->scope.' Line: '.$engine->statementLine);
}

$exitcode = $certValid ? 0 : 1;
$fileUrls = [];
$report = [
    'sharepoint' => [],
    'pohoda' => [],
    'pohodaSQL' => [],
    'certificate_valid' => $certValid,
];

if (!$certValid) {
    $engine->addStatusMessage(_('Certificate validation failed. Skipping statement processing.'), 'error');
    $report['error'] = 'Invalid certificate';
} else {
    $engine->addStatusMessage('stage 1/6: Download PDF Statements from Raiffeisen Bank account '.$engine->getAccount(), 'debug');

    try {
        $pdfStatements = $engine->downloadPDF();
        
        if ($pdfStatements === null || $pdfStatements === false || empty($pdfStatements)) {
            // Check if there were errors in stderr/messages indicating auth failure
            $messages = $engine->getStatusMessages();
            $hasAuthError = false;
            $errorMessage = '';
            foreach ($messages as $msg) {
                // Message objects have a body property
                if (is_object($msg) && isset($msg->body)) {
                    $msgText = $msg->body;
                } elseif (is_string($msg)) {
                    $msgText = $msg;
                } else {
                    continue;
                }
                
                if (stripos($msgText, '401') !== false || stripos($msgText, 'UNAUTHORISED') !== false || stripos($msgText, 'Certificate is blocked') !== false) {
                    $hasAuthError = true;
                    $errorMessage = $msgText;
                    break;
                }
            }
            
            if ($hasAuthError || $pdfStatements === null || $pdfStatements === false) {
                $report['raiffeisenbank']['pdf'] = 'download failed';
                $report['message'] = $errorMessage ?: 'PDF download failed - authentication or certificate error';
                $pdfStatements = [];
                if ($exitcode === 0) {
                    $exitcode = 401; // Certificate or auth issue
                }
            } else {
                $report['raiffeisenbank']['pdf'] = array_values($pdfStatements);
            }
        } else {
            $report['raiffeisenbank']['pdf'] = array_values($pdfStatements);
        }
    } catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
        $report['message'] = $exc->getMessage();
        $report['raiffeisenbank']['pdf'] = 'download failed';
        $pdfStatements = [];
        
        $apiExitCode = $exc->getCode();
        if (!$apiExitCode) {
            if (preg_match('/cURL error ([0-9]*):/', $report['message'], $codeRaw)) {
                $apiExitCode = (int) $codeRaw[1];
            } else {
                $apiExitCode = 1;
            }
        }
        
        // Only update exit code if not already set or if new error is more severe
        if ($exitcode === 0 || $apiExitCode > 0) {
            $exitcode = $apiExitCode;
        }
    }

    $engine->addStatusMessage('stage 2/6: Upload PDF Statements to SharePoint', 'debug');

    if ($pdfStatements) {
        sleep(5);
        $pdfStatements = $engine->getPdfStatements();

        if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
            $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
            $engine->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME').' and OFFICE365_PASSWORD', 'debug');
        } else {
            $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
            $engine->addStatusMessage('Using OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID').' and OFFICE365_CLSECRET', 'debug');
        }

        $ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
        $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

        $engine->addStatusMessage('ServiceRootUrl: '.$ctx->getServiceRootUrl(), 'debug');

        foreach ($pdfStatements as $filename) {
            $uploadAs = Statementor::statementFilename($filename);

            // Extract the date from the filename
            preg_match('/\d{4}-\d{2}-\d{2}/', $uploadAs, $dateMatches);
            $statementDate = $dateMatches[0] ?? '';

            $uploadFile = $targetFolder->uploadFile($uploadAs, file_get_contents($filename));

            try {
                $ctx->executeQuery();
                $uploaded = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($uploadFile->getServerRelativeUrl());
                $engine->addStatusMessage(_('Uploaded').': '.$uploaded, 'success');
                $report['sharepoint'][$uploadAs] = $uploaded;
                $fileUrls[$uploadAs] = $uploaded;
                $dayUrls[$statementDate][$uploadAs] = $uploaded;
            } catch (\Exception $exc) {
                $report['sharepoint'][$uploadAs] = $exc->getMessage();
                $engine->addStatusMessage($exc->getMessage());
                if ($exitcode === 0) {
                    $exitcode = 1;
                }
            }
        }
    } else {
        if (null === $pdfStatements) {
            $engine->addStatusMessage(_('Error obtaining PDF statements'), 'error');
            if ($exitcode === 0) {
                $exitcode = 2;
            }
        } else {
            $engine->addStatusMessage(_('No PDF statements obtained'), 'info');
        }
    }

    sleep(5);

    try {
        $engine->addStatusMessage('stage 3/6: Download XML Statements from Raiffeisen Bank account '.$engine->getAccount(), 'debug');
        $xmlStatements = $engine->downloadXML();
        
        if ($xmlStatements === null || $xmlStatements === false || empty($xmlStatements)) {
            // Check if there were errors in stderr/messages indicating auth failure
            $messages = $engine->getStatusMessages();
            $hasAuthError = false;
            $xmlErrorMessage = '';
            foreach ($messages as $msg) {
                // Message objects have a body property
                if (is_object($msg) && isset($msg->body)) {
                    $msgText = $msg->body;
                } elseif (is_string($msg)) {
                    $msgText = $msg;
                } else {
                    continue;
                }
                
                if (stripos($msgText, '401') !== false || stripos($msgText, 'UNAUTHORISED') !== false || stripos($msgText, 'Certificate is blocked') !== false) {
                    $hasAuthError = true;
                    $xmlErrorMessage = $msgText;
                    break;
                }
            }
            
            if ($hasAuthError || $xmlStatements === null || $xmlStatements === false) {
                $report['raiffeisenbank']['xml'] = 'download failed';
                $report['message'] = $xmlErrorMessage ?: 'XML download failed - authentication or certificate error';
                $xmlStatements = false;
                if ($exitcode === 0) {
                    $exitcode = 401; // Certificate or auth issue
                }
            } else {
                $report['raiffeisenbank']['xml'] = array_values($xmlStatements);
            }
        } else {
            $report['raiffeisenbank']['xml'] = array_values($xmlStatements);
        }
    } catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
        $engine->addStatusMessage($exc->getMessage(), 'error');
        $report['raiffeisenbank']['xml'] = 'download failed';
        $report['message'] = $exc->getMessage();
        
        $apiExitCode = $exc->getCode();
        if (!$apiExitCode) {
            if (preg_match('/cURL error ([0-9]*):/', $report['message'], $codeRaw)) {
                $apiExitCode = (int) $codeRaw[1];
            } else {
                $apiExitCode = 1;
            }
        }
        
        // Only update exit code if not already set or if new error is more severe
        if ($exitcode === 0 || $apiExitCode > 0) {
            $exitcode = $apiExitCode;
        }
        
        $xmlStatements = false;
    }

    if ($xmlStatements) {
        if ($engine->isOnline()) {
            $engine->addStatusMessage('stage 4/6: Import XML Statements to Pohoda', 'debug');
            $inserted = $engine->import(Shared::cfg('POHODA_BANK_IDS', ''));

            $report['pohoda'] = $inserted;

            $report['messages'] = $engine->getMessages();

            if ($engine->getExitCode() && $exitcode === 0) {
                $exitcode = $engine->getExitCode();
            }

            if ($inserted) {
                $engine->addStatusMessage('stage 5/6: Add Sharepoint links to Pohoda', 'debug');

                if ($fileUrls) {
                    $engine->addStatusMessage(sprintf(_('Updating PohodaSQL to attach statements in sharepoint links to invoice for %d'), \count($inserted)), 'debug');

                    $doc = new \SpojeNet\PohodaSQL\DOC();
                    $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

                    foreach ($inserted as $refId => $importInfo) {
                        if (!isset($importInfo['details']) || !is_array($importInfo['details'])) {
                            $engine->addStatusMessage(sprintf(_('Skipping import %s: missing details'), $refId), 'warning');
                            continue;
                        }
                        
                        $pohodaId = $importInfo['details']['id'];
                        $dateStatement = $importInfo['details']['date'];

                        if (array_key_exists($dateStatement, $dayUrls)) {
                            $filename = key($dayUrls[$dateStatement]);
                            $sharepointUri = current($dayUrls[$dateStatement]);

                            try {
                                $result = $doc->urlAttachment((int) $pohodaId, $sharepointUri, basename($filename));
                                $doc->addStatusMessage(sprintf('#%d: %s 👉 %s', $refId, $pohodaId, $sharepointUri), $result ? 'success' : 'error');
                                $report['pohodaSQL'][$refId]['status'] = 'success';
                                $report['pohodaSQL'][$refId]['linkedTo'] = $pohodaId;
                            } catch (\Exception $ex) {
                                $engine->addStatusMessage(_('Cannot Update PohodaSQL to attach statements in sharepoint links to invoice'), 'error');
                                $report['pohodaSQL'][$pohodaId]['status'] = 'failed';
                                $report['pohodaSQL'][$pohodaId]['message'] = $ex->getMessage();
                                if ($exitcode === 0) {
                                    $exitcode = 4;
                                }
                            }
                        } else {
                            $engine->addStatusMessage(sprintf(_('No fresh statement for %s was uploaded to Sharepoint; Skipping PohodaSQL update'), $dateStatement), 'warning');
                        }
                    }
                } else {
                    $engine->addStatusMessage(_('No statements uploaded to Sharepoint; Skipping PohodaSQL update'), 'warning');
                }
            } else {
                $engine->addStatusMessage(_('Empty statement(s)'), 'warning');
            }
        } else {
            $engine->addStatusMessage('mServer error: '.$engine->lastCurlResponse, 'error');
            if ($exitcode === 0) {
                $exitcode = 3;
            }
        }
    } else {
        if (\is_array($xmlStatements)) {
            $engine->addStatusMessage(_('No XML statements obtained'), 'info');
        } else {
            $engine->addStatusMessage(_('Error Obtaining XML statements'), 'error');
            if ($exitcode === 0) {
                $exitcode = 3;
            }
        }
    }
}

$engine->addStatusMessage('stage 6/6: saving report', 'debug');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
