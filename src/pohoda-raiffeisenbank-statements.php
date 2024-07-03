<?php

/**
 * RaiffeisenBank - Statements importer.
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023-2024 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

use SpojeNet\PohodaSQL\Agenda;
use Ease\Shared;
use Office365\Runtime\Auth\ClientCredential;
use Office365\Runtime\Auth\UserCredentials;
use Office365\SharePoint\ClientContext;

require_once('../vendor/autoload.php');

define('APP_NAME', 'Pohoda RaiffeisenBank Statements');

/**
 * Get today's tramsactons list
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER'], isset($argv[1]) ? $argv[1] : '../.env');
PohodaBankClient::checkCertificatePresence(\Ease\Shared::cfg('CERT_FILE'));
$engine = new Statementor(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(\Ease\Shared::cfg('STATEMENT_IMPORT_SCOPE', 'last_month'));
$inserted = $engine->import();

//
//    [243] => Array
//        (
//            [id] => 243
//            [number] => KB102023
//            [actionType] => add
//        )
//
//    [244] => Array
//        (
//            [id] => 244
//            [number] => KB102023
//            [actionType] => add
//        )
//


$pdfs = $engine->getPdfStatements();

if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
    $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
} else {
    $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
}

$ctx = (new ClientContext('https://' . Shared::cfg('OFFICE365_TENANT') . '.sharepoint.com/sites/' . Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
$targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

foreach ($pdfs as $filename) {
    $uploadFile = $targetFolder->uploadFile(basename($filename), file_get_contents($filename));
    try {
        $ctx->executeQuery();
    } catch (Exception $exc) {
        fwrite(fopen('php://stderr', 'wb'), $exc->getMessage() . PHP_EOL);
        exit(1);
    }
    $fileUrl = $ctx->getBaseUrl() . '/_layouts/15/download.aspx?SourceUrl=' . urlencode($uploadFile->getServerRelativeUrl());
}



$doc = new \SpojeNet\PohodaSQL\DOC();
$doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); //Bank

foreach ($inserted as $id => $importInfo) {
    $statement = current($pdfs);
    //$url = \Ease\Shared::cfg('DOWNLOAD_LINK_PREFIX') . urlencode(basename($statement));
    $result = $doc->urlAttachment($id, $fileUrl, basename($statement));
    $doc->addStatusMessage($importInfo['number'] . ' ' . $fileUrl, is_null($result) ? 'error' : 'success');
}
