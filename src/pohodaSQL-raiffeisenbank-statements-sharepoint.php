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
    $engine->logBanner($engine->getAccount().' '.$engine->getCurrencyCode(), 'Scope: '.$engine->scope);
}

$exitcode = 0;
$fileUrls = [];
$report = [
    'sharepoint' => [],
    'pohoda' => [],
    'pohodaSQL' => [],
];

try {
    $pdfStatements = $engine->downloadPDF();
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
        $uploadFile = $targetFolder->uploadFile(basename($filename), file_get_contents($filename));

        try {
            $ctx->executeQuery();
            $uploaded = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($uploadFile->getServerRelativeUrl());
            $engine->addStatusMessage(_('Uploaded').': '.$uploaded, 'success');
            $report['sharepoint'][basename($filename)] = $uploaded;
            $fileUrls[basename($filename)] = $uploaded;
        } catch (\Exception $exc) {
            fwrite(fopen('php://stderr', 'wb'), $exc->getMessage().\PHP_EOL);

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
    $xmlStatements = $engine->downloadXML();
} catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
    $engine->addStatusMessage($exc->getMessage(), 'error');
    $exitcode = (int) $exc->getCode();
    $xmlStatements = false;
}

if ($xmlStatements) {
    $inserted = $engine->import(Shared::cfg('POHODA_BANK_IDS', ''));
    $report['pohoda'] = $inserted;

    $report['messages'] = $engine->getMessages();
    $report['exitcode'] = $engine->getExitCode();

    if ($engine->getExitCode()) {
        $exitcode = $engine->getExitCode();
    }

    if ($inserted) {
        if ($fileUrls) {
            $engine->addStatusMessage(sprintf(_('Updating PohodaSQL to attach statements in sharepoint links to invoice for %d'), \count($inserted)), 'debug');

            $doc = new \SpojeNet\PohodaSQL\DOC();
            $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

            $filename = key($fileUrls);
            $sharepointUri = current($fileUrls);

            foreach ($inserted as $importInfo) {
                $id = $importInfo['id'];

                try {
                    $result = $doc->urlAttachment((int) $id, $sharepointUri, basename($filename));
                    $doc->addStatusMessage(sprintf('#%d: %s %s', $id, $importInfo['number'], $sharepointUri), $result ? 'success' : 'error');
                    $report['pohodaSQL'][$id] = $importInfo['number'];
                } catch (\Exception $ex) {
                    $engine->addStatusMessage(_('Cannot Update PohodaSQL to attach statements in sharepoint links to invoice'), 'error');
                    $report['pohodaSQL'][$id] = $ex->getMessage();
                    $exitcode = 4;
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

$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
