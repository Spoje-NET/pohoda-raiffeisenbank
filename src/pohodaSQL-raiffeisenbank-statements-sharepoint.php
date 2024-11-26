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
Shared::init([
    'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
    'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
    'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ], $argv[1] ?? '../.env');

PohodaBankClient::checkCertificatePresence(Shared::cfg('CERT_FILE'));
$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));
$engine->logBanner('', 'Scope: ' . $engine->scope);
$exitcode = 0;
$fileUrls = [];

$pdfStatements = $engine->downloadPDF();

if ($pdfStatements) {
    sleep(5);

    $pdfs = $engine->getPdfStatements();

    if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
        $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
        $engine->addStatusMessage('Using OFFICE365_USERNAME ' . Shared::cfg('OFFICE365_USERNAME') . ' and OFFICE365_PASSWORD', 'debug');
    } else {
        $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
        $engine->addStatusMessage('Using OFFICE365_CLIENTID ' . Shared::cfg('OFFICE365_CLIENTID') . ' and OFFICE365_CLSECRET', 'debug');
    }

    $ctx = (new ClientContext('https://' . Shared::cfg('OFFICE365_TENANT') . '.sharepoint.com/sites/' . Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
    $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

    $engine->addStatusMessage('ServiceRootUrl: ' . $ctx->getServiceRootUrl(), 'debug');

    foreach ($pdfs as $filename) {
        $uploadFile = $targetFolder->uploadFile(basename($filename), file_get_contents($filename));

        try {
            $ctx->executeQuery();
            $uploaded = $ctx->getBaseUrl() . '/_layouts/15/download.aspx?SourceUrl=' . urlencode($uploadFile->getServerRelativeUrl());
            $engine->addStatusMessage(_('Uploaded') . ': ' . $uploaded, 'success');
        } catch (\Exception $exc) {
            fwrite(fopen('php://stderr', 'wb'), $exc->getMessage() . \PHP_EOL);

            exit(1);
        }

        $fileUrls[basename($filename)] = $uploaded;
    }
} else {
    if (\is_array($pdfStatements)) {
        $engine->addStatusMessage(_('No PDF statements obtained'), 'info');
    } else {
        $engine->addStatusMessage(_('Error obtaining PDF statements'), 'error');
        $exitcode = 2;
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
    $inserted = $engine->import();

    if ($inserted) {
        if ($fileUrls) {
            $engine->addStatusMessage(_('Updating PohodaSQL to attach statements in sharepoint links to invoice'), 'success');

            $doc = new \SpojeNet\PohodaSQL\DOC();
            $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

            $filename = key($fileUrls);
            $sharepointUri = current($fileUrls);

            foreach ($inserted as $importInfo) {
                $id = $importInfo['id'];

                try {
                    $result = $doc->urlAttachment((int) $id, $sharepointUri, basename($filename));
                    $doc->addStatusMessage($importInfo['number'] . ' ' . $sharepointUri, $result ? 'success' : 'error');
                } catch (\Exception $ex) {
                    $engine->addStatusMessage(_('Cannot Update PohodaSQL to attach statements in sharepoint links to invoice'), 'error');

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


exit($exitcode);
