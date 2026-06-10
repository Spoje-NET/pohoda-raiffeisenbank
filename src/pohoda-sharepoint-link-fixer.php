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

\define('APP_NAME', 'PohodaSharepointLinkFixer');

require_once '../vendor/autoload.php';

/**
 * Retroactively attach missing SharePoint PDF links to Pohoda bank records.
 *
 * During the period when the SharePoint attachment was broken (MSSQL support
 * was temporarily removed), bank statements were uploaded to SharePoint but
 * the corresponding URL links were not written into Pohoda's DOC table.
 * This tool finds those bank records and attaches the missing links.
 */
$options = getopt('o::e:', ['output::environment:']);
Shared::init(
    [
        'OFFICE365_TENANT', 'OFFICE365_PATH', 'OFFICE365_SITE',
        'ACCOUNT_NUMBER',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
        'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$since = new \DateTime(Shared::cfg('DATE_FROM', 'first day of last month'));
$until = new \DateTime(Shared::cfg('DATE_TO', 'last day of last month'));
$until->setTime(23, 59, 59);

$exitcode = 0;
$report = [
    'account' => Shared::cfg('ACCOUNT_NUMBER'),
    'bank_ids' => Shared::cfg('POHODA_BANK_IDS', null),
    'since' => $since->format('Y-m-d'),
    'until' => $until->format('Y-m-d'),
    'sharepoint_files' => [],
    'bank_records_checked' => 0,
    'fixed' => [],
    'skipped' => [],
    'errors' => [],
];

$logger = new \Ease\Sand();
$logger->setObjectName('PohodaSharepointLinkFixer');

if (Shared::cfg('APP_DEBUG', false)) {
    $logger->logBanner(APP_NAME, sprintf('Period: %s → %s | Account: %s', $since->format('Y-m-d'), $until->format('Y-m-d'), Shared::cfg('ACCOUNT_NUMBER')));
}

// Stage 1: Connect to SharePoint and list all PDF statement files
$logger->addStatusMessage('stage 1/3: Listing SharePoint statement PDFs', 'debug');

if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
    $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
    $logger->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME'), 'debug');
} else {
    $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
    $logger->addStatusMessage('Using OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID'), 'debug');
}

$ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
$targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

// Date → ['filename' => ..., 'url' => ...] mapping built from SharePoint filenames.
// Filename pattern: {statNum}_{account}_{accountId}_{currency}_{YYYY-MM-DD}.pdf
$dateToSharepoint = [];

try {
    $sharepointFilesRaw = $targetFolder->getFiles()->get()->executeQuery();

    // @phpstan-ignore foreach.nonIterable
    foreach ($sharepointFilesRaw as $spFile) {
        $name = $spFile->getName();

        if (!preg_match('/\.pdf$/i', $name)) {
            continue;
        }

        $accountInFilename = str_replace('/', '_', Shared::cfg('ACCOUNT_NUMBER'));

        if (!str_contains($name, '_'.$accountInFilename.'_')) {
            continue;
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})\.pdf$/i', $name, $dateMatch)) {
            $fileDate = $dateMatch[1];

            if ($fileDate < $since->format('Y-m-d') || $fileDate > $until->format('Y-m-d')) {
                continue;
            }

            $url = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($spFile->getServerRelativeUrl());
            $dateToSharepoint[$fileDate] = ['filename' => $name, 'url' => $url];
            $logger->addStatusMessage(sprintf('SharePoint: %s (%s)', $name, $fileDate), 'debug');
        }
    }

    $report['sharepoint_files'] = array_keys($dateToSharepoint);
    $logger->addStatusMessage(sprintf('Found %d statement PDFs in SharePoint for period', \count($dateToSharepoint)), 'info');
} catch (\Exception $exc) {
    $logger->addStatusMessage('SharePoint error: '.$exc->getMessage(), 'error');
    $report['errors'][] = 'SharePoint listing failed: '.$exc->getMessage();
    $exitcode = 1;
}

if (empty($dateToSharepoint)) {
    $logger->addStatusMessage('No SharePoint files found for the specified period — nothing to fix.', 'warning');
    $report['exitcode'] = $exitcode;
    file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));

    exit($exitcode);
}

// Stage 2: Query Pohoda MSSQL for bank records in the date range that are missing URL attachments
$logger->addStatusMessage('stage 2/3: Querying MSSQL for bank records without SharePoint links', 'debug');

$doc = new \SpojeNet\PohodaSQL\DOC(null, [
    'dbType' => Shared::cfg('DB_CONNECTION', 'sqlsrv'),
    'server' => Shared::cfg('DB_HOST'),
    'dbLogin' => Shared::cfg('DB_USERNAME'),
    'dbPass' => Shared::cfg('DB_PASSWORD'),
    'database' => Shared::cfg('DB_DATABASE'),
    'port' => Shared::cfg('DB_PORT', '1433'),
    'dbSettings' => Shared::cfg('DB_SETTINGS', ''),
]);
$doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK);

$bankRecords = [];

try {
    $fpdo = $doc->getFluentPDO(true);
    $query = $fpdo
        ->from('BV')
        ->disableSmartJoin()
        ->select('BV.ID, BV.Datum, BV.Cislo, BV.Vypis')
        ->where('BV.Datum >= ?', $since->format('Y-m-d H:i:s'))
        ->where('BV.Datum <= ?', $until->format('Y-m-d H:i:s'))
        ->where(sprintf(
            'NOT EXISTS (SELECT 1 FROM DOC WHERE DOC.RelAgID = %d AND DOC.RelID = BV.ID AND DOC.RelDocType = 3)',
            \SpojeNet\PohodaSQL\Agenda::BANK,
        ));

    if (Shared::cfg('POHODA_BANK_IDS', false)) {
        $bankIds = array_map('trim', explode(',', Shared::cfg('POHODA_BANK_IDS')));
        $query = $query
            ->join('sUcet ON BV.RefUcet = sUcet.ID')
            ->where('sUcet.IDS IN ?', $bankIds);
    }

    $bankRecords = $query->fetchAll();

    $report['bank_records_checked'] = \count($bankRecords);
    $logger->addStatusMessage(sprintf('Found %d bank records without URL attachment', \count($bankRecords)), 'info');
} catch (\Exception $exc) {
    $logger->addStatusMessage('MSSQL query failed: '.$exc->getMessage(), 'error');
    $report['errors'][] = 'MSSQL query failed: '.$exc->getMessage();
    $exitcode = 2;
}

// Stage 3: Attach the missing SharePoint links
$logger->addStatusMessage('stage 3/3: Attaching missing SharePoint links to Pohoda bank records', 'debug');

foreach ($bankRecords as $record) {
    $recordDate = (new \DateTime($record['Datum']))->format('Y-m-d');

    if (\array_key_exists($recordDate, $dateToSharepoint)) {
        $sp = $dateToSharepoint[$recordDate];

        try {
            $result = $doc->urlAttachment((int) $record['ID'], $sp['url'], $sp['filename']);

            if ($result) {
                $logger->addStatusMessage(sprintf('#%d (%s) → %s', $record['ID'], $recordDate, $sp['filename']), 'success');
                $report['fixed'][] = [
                    'pohodaId' => (int) $record['ID'],
                    'cislo' => $record['Cislo'],
                    'date' => $recordDate,
                    'filename' => $sp['filename'],
                    'url' => $sp['url'],
                ];
            } else {
                $logger->addStatusMessage(sprintf('Failed to attach link to record #%d (%s)', $record['ID'], $recordDate), 'error');
                $report['errors'][] = sprintf('Attachment insert failed for record #%d (%s)', $record['ID'], $recordDate);

                if ($exitcode === 0) {
                    $exitcode = 3;
                }
            }
        } catch (\Exception $exc) {
            $logger->addStatusMessage(sprintf('Error attaching to record #%d: %s', $record['ID'], $exc->getMessage()), 'error');
            $report['errors'][] = sprintf('Error for record #%d: %s', $record['ID'], $exc->getMessage());

            if ($exitcode === 0) {
                $exitcode = 3;
            }
        }
    } else {
        $logger->addStatusMessage(sprintf('No SharePoint statement found for date %s (record #%d)', $recordDate, $record['ID']), 'warning');
        $report['skipped'][] = [
            'pohodaId' => (int) $record['ID'],
            'date' => $recordDate,
            'reason' => 'No SharePoint PDF found for this date',
        ];
    }
}

$logger->addStatusMessage(sprintf('Done: %d fixed, %d skipped, %d errors', \count($report['fixed']), \count($report['skipped']), \count($report['errors'])), $exitcode === 0 ? 'success' : 'warning');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$logger->addStatusMessage(sprintf('Saving result to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
