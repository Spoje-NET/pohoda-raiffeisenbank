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
$engine->logBanner('', 'Scope: '.$engine->scope);

if ($engine->downloadPDF()) {
    sleep(5);

    $pdfs = $engine->getPdfStatements();

    if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
        $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
        $engine->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME').' and OFFICE365_PASSWORD', 'debug');
    } else {
        $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
        $engine->addStatusMessage('Using OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID').' and OFFICE365_CLSECRET', 'debug');
    }

    $ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
    $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

    $engine->addStatusMessage('using '.$ctx->getServiceRootUrl(), 'debug');

    foreach ($pdfs as $filename) {
        $uploadFile = $targetFolder->uploadFile(basename($filename), file_get_contents($filename));

        try {
            $ctx->executeQuery();
            $uploaded = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($uploadFile->getServerRelativeUrl());
            $engine->addStatusMessage(_('Uploaded').': '.$uploaded, 'success');
        } catch (\Exception $exc) {
            fwrite(fopen('php://stderr', 'wb'), $exc->getMessage().\PHP_EOL);

            exit(1);
        }

        $fileUrls[basename($filename)] = $uploaded;
    }
} else {
    $engine->addStatusMessage(_('Error obtaining PDF'), 'error');
}

sleep(5);

if ($engine->downloadXML()) {
    $inserted = $engine->import();

    if ($inserted) {
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
        $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK); // Bank

        foreach ($inserted as $id => $importInfo) {
            $filename = key($fileUrls);
            $statement = next($fileUrls);
            // $url = \Ease\Shared::cfg('DOWNLOAD_LINK_PREFIX') . urlencode(basename($statement));
            $result = $doc->urlAttachment((int) $id, $filename, basename($statement));
            $doc->addStatusMessage($importInfo['number'].' '.$fileUrl, null === $result ? 'error' : 'success');
        }
    } else {
        $engine->addStatusMessage(_('Error Importing XML statements to Pohoda'), 'error');

        exit(2);
    }
} else {
    $engine->addStatusMessage(_('Error Obtaining XML statements'), 'error');

    exit(3);
}
