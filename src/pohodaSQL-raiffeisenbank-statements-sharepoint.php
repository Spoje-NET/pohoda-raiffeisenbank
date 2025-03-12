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
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout'));

PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));
$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));

if (Shared::cfg('STATEMENT_LINE')) {
    $engine->setStatementLine(Shared::cfg('STATEMENT_LINE'));
}

if (Shared::cfg('APP_DEBUG', false)) {
    $engine->logBanner($engine->getAccount().' '.$engine->getCurrencyCode(), ' Scope: '.$engine->scope.' Line: '.$engine->statementLine);
}

$exitcode = 0;
$fileUrls = [];
$report = [
    'sharepoint' => [],
    'pohoda' => [],
    'pohodaSQL' => [],
];

$engine->addStatusMessage('stage 1/6: Download PDF Statements from Raiffeisen Bank account '.$engine->getAccount(), 'debug');

try {
    $pdfStatements = $engine->downloadPDF();
    $report['raiffeisenbank']['pdf'] = array_values($pdfStatements);
} catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
    $report['mesage'] = $exc->getMessage();
    $pdfStatements = [];
    $exitcode = $exc->getCode();

    if (!$exitcode) {
        if (preg_match('/cURL error ([0-9]*):/', $report['mesage'], $codeRaw)) {
            $exitcode = (int) $codeRaw[1];
        }
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
            fwrite(fopen('php://stderr', 'wb'), $exc->getMessage().\PHP_EOL);
            $report['sharepoint'][$uploadAs] = 'upload failed';
            $exitcode = 1;
        }
    }
} else {
    if (null === $pdfStatements) {
        $engine->addStatusMessage(_('Error obtaining PDF statements'), 'error');
        $exitcode = 2;
    } else {
        $engine->addStatusMessage(_('No PDF statements obtained'), 'info');
    }
}

sleep(5);

try {
    $engine->addStatusMessage('stage 3/6: Download XML Statements from Raiffeisen Bank account '.$engine->getAccount(), 'debug');
    $xmlStatements = $engine->downloadXML();
    $report['raiffeisenbank']['xml'] = array_values($xmlStatements);
} catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
    $engine->addStatusMessage($exc->getMessage(), 'error');
    $report['raiffeisenbank']['xml'] = 'download failed';
    $exitcode = (int) $exc->getCode();
    $xmlStatements = false;
}

if ($xmlStatements) {
    $engine->addStatusMessage('stage 4/6: Import XML Statements to Pohoda', 'debug');
    $inserted = $engine->import(Shared::cfg('POHODA_BANK_IDS', ''));
    $report['pohoda'] = $inserted;

    $report['messages'] = $engine->getMessages();

    if ($engine->getExitCode()) {
        $exitcode = $engine->getExitCode();
    }

    if ($inserted) {
        $engine->addStatusMessage('stage 5/6: Add Sharepoint links to Pohoda', 'debug');

        if ($fileUrls) {
            $engine->addStatusMessage(sprintf(_('Updating PohodaSQL to attach statements in sharepoint links to invoice for %d'), \count($inserted)), 'debug');

            $doc = new \SpojeNet\PohodaSQL\DOC();
            $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

            foreach ($inserted as $refId => $importInfo) {
                $pohodaId = $importInfo['details']['id'];
                $dateStatement = $importInfo['details']['date'];

                if (\array_key_exists($dateStatement, $dayUrls)) {
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
                        $exitcode = 4;
                    }
                } else {
                    $engine->addStatusMessage(sprintf(_('No fresh statement for %s was uploaded to Sharepoint; Skipping PohodaSQL update'), $dateStatement), 'warning');
                }
            }
        } else {
            $engine->addStatusMessage(_('No statements uploaded to Sharepoint; Skipping PohodaSQL update'), 'warning');
        }
    } else {
        $engine->addStatusMessage(_('Empty statement'), 'warning');
    }
} else {
    if (\is_array($xmlStatements)) {
        $engine->addStatusMessage(_('No XML statements obtained'), 'info');
    } else {
        $engine->addStatusMessage(_('Error Obtaining XML statements'), 'error');
        $exitcode = 3;
    }
}

$engine->addStatusMessage('stage 6/6: saving report', 'debug');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
