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
 * Get today's transactions list.
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'ACCOUNT_NUMBER'], $argv[2] ?? '../.env');
$xmlFile = \Ease\Shared::cfg('STATEMENT_FILE', $argv[1] ?? '');

$engine = new Statementor(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
$inserted = $engine->importXML($xmlFile);

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

$ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
$targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

foreach ($pdfs as $filename) {
    $uploadFile = $targetFolder->uploadFile(basename($filename), file_get_contents($filename));

    try {
        $ctx->executeQuery();
    } catch (Exception $exc) {
        fwrite(fopen('php://stderr', 'wb'), $exc->getMessage().\PHP_EOL);

        exit(1);
    }

    $fileUrl = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($uploadFile->getServerRelativeUrl());
}

$doc = new \SpojeNet\PohodaSQL\DOC();
$doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

foreach ($inserted as $id => $importInfo) {
    $statement = current($pdfs);
    // $url = \Ease\Shared::cfg('DOWNLOAD_LINK_PREFIX') . urlencode(basename($statement));
    $result = $doc->urlAttachment($id, $fileUrl, basename($statement));
    $doc->addStatusMessage($importInfo['number'].' '.$fileUrl, null === $result ? 'error' : 'success');
}
