<?php

/**
 * RaiffeisenBank - Statements importer.
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

use SpojeNet\PohodaSQL\Agenda;

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


$doc = new \SpojeNet\PohodaSQL\DOC();
$doc->setDataValue('RelAgID', Agenda::BANK); //Bank

foreach ($inserted as $id => $importInfo) {
    $url = \Ease\Shared::cfg('DOWNLOAD_LINK_PREFIX') . $id;
    $result = $doc->urlAttachment('Sharepoint', $url, $id);
    $doc->addStatusMessage($importInfo['number'] . ' URL', is_null($result) ? 'error' : 'success');
}
