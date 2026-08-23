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

$scopeEngine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));
$period = $scopeEngine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));
$since = \DateTime::createFromInterface($period->getStartDate());
$until = \DateTime::createFromInterface($period->getEndDate());

if (Shared::cfg('DATE_FROM', false)) {
    $since = new \DateTime(Shared::cfg('DATE_FROM'));
}

if (Shared::cfg('DATE_TO', false)) {
    $until = new \DateTime(Shared::cfg('DATE_TO'));
}

$until->setTime(23, 59, 59);

// When false (default) the tool only reports what it WOULD change and writes
// nothing to Pohoda — run once in this dry-run mode, review, then set true.
$apply = filter_var(Shared::cfg('LINK_FIX_APPLY', false), \FILTER_VALIDATE_BOOLEAN);

$exitcode = 0;
$report = [
    'account' => Shared::cfg('ACCOUNT_NUMBER'),
    'bank_ids' => Shared::cfg('POHODA_BANK_IDS', null),
    'since' => $since->format('Y-m-d'),
    'until' => $until->format('Y-m-d'),
    'apply' => $apply,
    'sharepoint_files' => [],
    'bank_records_checked' => 0,
    'ok' => 0,        // records whose link is already correct — left untouched
    'fixed' => [],    // missing link → newly attached
    'corrected' => [], // wrong-account link → repointed to the correct PDF
    'removed' => [],  // wrong-account link with no correct PDF → deleted
    'skipped' => [],  // missing link but no SharePoint PDF for that date
    'xml_moved' => [], // XML statement relocated from OFFICE365_PATH to OFFICE365_PATH_XML
    'errors' => [],
];

$logger = new \Ease\Sand();
$logger->setObjectName('PohodaSharepointLinkFixer');

if (Shared::cfg('APP_DEBUG', false)) {
    $logger->logBanner(APP_NAME, sprintf('Period: %s → %s | Account: %s | %s', $since->format('Y-m-d'), $until->format('Y-m-d'), Shared::cfg('ACCOUNT_NUMBER'), $apply ? 'APPLY' : 'DRY-RUN'));
}

// Stage 1: Connect to SharePoint and list all PDF statement files
$logger->addStatusMessage('stage 1/3: Listing SharePoint statement PDFs', 'debug');

if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
    // Legacy user credential flow, untouched by the ACS retirement - still
    // goes through classic SharePoint REST (_api/web/...).
    $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
    $logger->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME'), 'debug');
    $ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
    $resetAuth = static function () use ($ctx, $credentials): void {
        $ctx->withCredentials($credentials);
    };
    $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

    $doList = static function () use ($ctx, $resetAuth, $targetFolder): array {
        $sharepointFilesRaw = PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($targetFolder) {
            return $targetFolder->getFiles()->get()->executeQuery();
        });
        $files = [];

        // @phpstan-ignore foreach.nonIterable
        foreach ($sharepointFilesRaw as $spFile) {
            $files[$spFile->getName()] = $ctx->getBaseUrl().'/_layouts/15/download.aspx?SourceUrl='.urlencode($spFile->getServerRelativeUrl());
        }

        return $files;
    };
    $doMoveXml = static function (string $filename) use ($ctx, $resetAuth): void {
        PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($ctx, $filename) {
            $sourceUrl = rtrim(Shared::cfg('OFFICE365_PATH'), '/').'/'.$filename;
            $destUrl = rtrim(Shared::cfg('OFFICE365_PATH_XML'), '/').'/'.$filename;
            $ctx->getWeb()->getFileByServerRelativeUrl($sourceUrl)->moveToEx($destUrl, true);

            return $ctx->executeQuery();
        });
    };
} else {
    // Client-id/secret (app-only) case goes through Microsoft Graph, not
    // classic SharePoint REST - see PohodaBankClientOffice's class docblock
    // for why (client-secret tokens are unconditionally rejected by
    // _api/web/... regardless of permissions granted, "Unsupported app only
    // token.").
    $logger->addStatusMessage('Using Microsoft Graph API (Entra ID v2 app-only) with OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID'), 'debug');
    $graph = PohodaBankClientOffice::buildGraphClient(
        Shared::cfg('OFFICE365_TENANT'),
        Shared::cfg('OFFICE365_SITE'),
        Shared::cfg('OFFICE365_CLIENTID'),
        Shared::cfg('OFFICE365_CLSECRET'),
    );
    $path = Shared::cfg('OFFICE365_PATH');
    $permanentLink = Shared::cfg('SHAREPOINT_PERMANENT_LINK', 'true') !== 'false';
    $linkType = Shared::cfg('SHAREPOINT_LINK_TYPE', 'view');
    $linkScope = Shared::cfg('SHAREPOINT_LINK_SCOPE', 'organization');

    // Same permanent-link behavior as the uploaders: prefer a Graph
    // createLink share URL (stable across move/rename) over the path-based
    // webUrl, so this tool can also upgrade old-format links to the new one.
    $xmlItemIds = [];
    $doList = static function () use ($graph, $path, $permanentLink, $linkType, $linkScope, &$xmlItemIds): array {
        $files = [];

        foreach ($graph->listFilesDetailed($path) as $name => $item) {
            $files[$name] = $permanentLink ? $graph->createShareLink($item['id'], $linkType, $linkScope) : $item['webUrl'];
            $xmlItemIds[$name] = $item['id'];
        }

        return $files;
    };
    $doMoveXml = static function (string $filename) use ($graph, &$xmlItemIds): void {
        $graph->moveFile($xmlItemIds[$filename], Shared::cfg('OFFICE365_PATH_XML'));
    };
}

// Date → ['filename' => ..., 'url' => ...] mapping built from SharePoint filenames.
// Filename pattern: {statNum}_{account}_{accountId}_{currency}_{YYYY-MM-DD}.pdf
$dateToSharepoint = [];

// XML statements uploaded under the old single-folder convention (before
// OFFICE365_PATH_XML existed, or with SHAREPOINT_UPLOAD_XML/OFFICE365_PATH_XML
// changed later) sit alongside the PDFs in OFFICE365_PATH - collect the ones
// belonging to this account/period here, and relocate them below once a
// distinct OFFICE365_PATH_XML is actually configured.
$xmlFilesToMove = [];
$moveXmlEnabled = Shared::cfg('OFFICE365_PATH_XML', false)
    && rtrim((string) Shared::cfg('OFFICE365_PATH_XML'), '/') !== rtrim((string) Shared::cfg('OFFICE365_PATH'), '/');

try {
    $sharepointFiles = $doList();

    foreach ($sharepointFiles as $name => $url) {
        // PHP silently coerces purely-numeric string array keys to int (e.g.
        // a SharePoint item literally named "20260821"), so $name isn't
        // reliably a string here even though both $doList() branches build
        // it from a filename - cast back before using it as one.
        $name = (string) $name;

        if ($moveXmlEnabled
            && preg_match('/(\d{4}-\d{2}-\d{2})\.xml$/i', $name, $xmlDateMatch)
            && Statementor::filenameMatchesAccount($name, (string) Shared::cfg('ACCOUNT_NUMBER'))
            && $xmlDateMatch[1] >= $since->format('Y-m-d') && $xmlDateMatch[1] <= $until->format('Y-m-d')
        ) {
            $xmlFilesToMove[] = $name;
        }

        if (!preg_match('/\.pdf$/i', $name)) {
            continue;
        }

        if (!Statementor::filenameMatchesAccount($name, (string) Shared::cfg('ACCOUNT_NUMBER'))) {
            continue;
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})\.pdf$/i', $name, $dateMatch)) {
            $fileDate = $dateMatch[1];

            if ($fileDate < $since->format('Y-m-d') || $fileDate > $until->format('Y-m-d')) {
                continue;
            }

            $dateToSharepoint[$fileDate] = ['filename' => $name, 'url' => $url];
            $logger->addStatusMessage(sprintf('SharePoint: %s (%s)', $name, $fileDate), 'debug');
        }
    }

    $report['sharepoint_files'] = array_keys($dateToSharepoint);
    $logger->addStatusMessage(sprintf('Found %d statement PDFs in SharePoint for period', \count($dateToSharepoint)), 'info');
} catch (\Exception $exc) {
    $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint file listing');
    $logger->addStatusMessage($errorMessage, 'error');
    $report['errors'][] = $errorMessage;
    $exitcode = 1;
}

if (!empty($xmlFilesToMove)) {
    $logger->addStatusMessage(sprintf('%s %d XML statement(s) from %s to %s', $apply ? 'Moving' : 'Would move', \count($xmlFilesToMove), Shared::cfg('OFFICE365_PATH'), Shared::cfg('OFFICE365_PATH_XML')), 'debug');

    foreach ($xmlFilesToMove as $xmlFilename) {
        try {
            if ($apply) {
                $doMoveXml($xmlFilename);
            }

            $logger->addStatusMessage(sprintf('%s %s → %s', $apply ? 'moved' : 'would move', $xmlFilename, Shared::cfg('OFFICE365_PATH_XML')), 'success');
            $report['xml_moved'][] = $xmlFilename;
        } catch (\Exception $exc) {
            $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'XML move of '.$xmlFilename);
            $logger->addStatusMessage($errorMessage, 'error');
            $report['errors'][] = $errorMessage;

            if ($exitcode === 0) {
                $exitcode = 3;
            }
        }
    }
}

if (empty($dateToSharepoint)) {
    $logger->addStatusMessage('No SharePoint files found for the specified period — nothing to fix.', 'warning');
    $report['exitcode'] = $exitcode;
    file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));

    exit($exitcode);
}

// Stage 2: Query Pohoda MSSQL for bank records in the date range together with
// their current SharePoint URL attachment (if any), so Stage 3 can attach missing
// links AND repair links pointing to another account's statement.
$logger->addStatusMessage('stage 2/3: Querying MSSQL for bank records and their current SharePoint links', 'debug');

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
$fpdo = null;

try {
    $fpdo = $doc->getFluentPDO(true);
    $query = $fpdo
        ->from('BV')
        ->disableSmartJoin()
        ->select('BV.ID, BV.Datum, BV.Cislo, BV.Vypis')
        ->select(sprintf('(SELECT TOP 1 d.ID FROM DOC d WHERE d.RelAgID = %d AND d.RelID = BV.ID AND d.RelDocType = 3 ORDER BY d.ID DESC) AS docId', \SpojeNet\PohodaSQL\Agenda::BANK))
        ->select(sprintf('(SELECT TOP 1 d.Name FROM DOC d WHERE d.RelAgID = %d AND d.RelID = BV.ID AND d.RelDocType = 3 ORDER BY d.ID DESC) AS docName', \SpojeNet\PohodaSQL\Agenda::BANK))
        ->select(sprintf('(SELECT TOP 1 d.Url FROM DOC d WHERE d.RelAgID = %d AND d.RelID = BV.ID AND d.RelDocType = 3 ORDER BY d.ID DESC) AS docUrl', \SpojeNet\PohodaSQL\Agenda::BANK))
        ->where('BV.Datum >= ?', $since->format('Y-m-d H:i:s'))
        ->where('BV.Datum <= ?', $until->format('Y-m-d H:i:s'));

    if (Shared::cfg('POHODA_BANK_IDS', false)) {
        $bankIds = array_map('trim', explode(',', Shared::cfg('POHODA_BANK_IDS')));
        $query = $query
            ->join('sUcet ON BV.RefUcet = sUcet.ID')
            ->where('sUcet.IDS', $bankIds);
    }

    $bankRecords = $query->fetchAll();

    $report['bank_records_checked'] = \count($bankRecords);
    $logger->addStatusMessage(sprintf('Found %d bank records in period', \count($bankRecords)), 'info');
} catch (\Exception $exc) {
    $logger->addStatusMessage('MSSQL query failed: '.$exc->getMessage(), 'error');
    $report['errors'][] = 'MSSQL query failed: '.$exc->getMessage();
    $exitcode = 2;
}

// Stage 3: Attach missing links and repair wrong (cross-attached) ones.
// A link is "correct" when the account number is present in the attached
// filename — the same convention Stage 1 used to pick this account's PDFs.
$logger->addStatusMessage(sprintf('stage 3/3: %s SharePoint links on Pohoda bank records', $apply ? 'Repairing' : 'Evaluating (dry-run)'), 'debug');

$accountNumber = (string) Shared::cfg('ACCOUNT_NUMBER');

foreach ($bankRecords as $record) {
    $recordId = (int) $record['ID'];
    $recordDate = (new \DateTime($record['Datum']))->format('Y-m-d');
    $desired = $dateToSharepoint[$recordDate] ?? null;
    $docId = $record['docId'] ?? null;
    $docName = (string) ($record['docName'] ?? '');
    $docUrl = (string) ($record['docUrl'] ?? '');
    $hasLink = !empty($docId);
    // Besides the account matching the filename, the attached URL must also
    // match what Stage 1 would generate today — this is what lets old-format
    // links (stale webUrl) get upgraded to the current permanent share link.
    $linkCorrect = $hasLink
        && Statementor::filenameMatchesAccount($docName, $accountNumber)
        && ($desired === null || $docUrl === $desired['url']);

    try {
        if ($linkCorrect) {
            ++$report['ok'];

            continue;
        }

        if (!$hasLink) {
            // Missing link: attach this account's statement PDF for that date.
            if ($desired === null) {
                $report['skipped'][] = [
                    'pohodaId' => $recordId,
                    'date' => $recordDate,
                    'reason' => 'No SharePoint PDF found for this date',
                ];

                continue;
            }

            if ($apply && !$doc->urlAttachment($recordId, $desired['url'], $desired['filename'])) {
                throw new \RuntimeException('urlAttachment insert returned false');
            }

            $logger->addStatusMessage(sprintf('%s #%d (%s) → %s', $apply ? 'attached' : 'would attach', $recordId, $recordDate, $desired['filename']), 'success');
            $report['fixed'][] = [
                'pohodaId' => $recordId,
                'cislo' => $record['Cislo'],
                'date' => $recordDate,
                'filename' => $desired['filename'],
                'url' => $desired['url'],
            ];
        } elseif ($desired !== null) {
            // Wrong link, correct statement exists: repoint the existing DOC row.
            // Two possible causes: the filename points at another account, or
            // the filename is fine but the URL is in the stale format (e.g.
            // pre-permanent-link webUrl) and needs to be upgraded.
            $reason = Statementor::filenameMatchesAccount($docName, $accountNumber) ? 'format_upgrade' : 'wrong_account';

            if ($apply) {
                $fpdo->update('DOC')->set(['Name' => $desired['filename'], 'Url' => $desired['url']])->where('ID', (int) $docId)->execute();
            }

            $logger->addStatusMessage(sprintf('%s #%d (%s) %s → %s [%s]', $apply ? 'corrected' : 'would correct', $recordId, $recordDate, $docName, $desired['filename'], $reason), 'warning');
            $report['corrected'][] = [
                'pohodaId' => $recordId,
                'docId' => (int) $docId,
                'date' => $recordDate,
                'from' => $docName,
                'to' => $desired['filename'],
                'url' => $desired['url'],
                'reason' => $reason,
            ];
        } else {
            // Wrong link, no correct statement to point at: remove the misleading link.
            if ($apply) {
                $fpdo->deleteFrom('DOC')->where('ID', (int) $docId)->execute();
            }

            $logger->addStatusMessage(sprintf('%s #%d (%s) wrong link %s (no correct PDF)', $apply ? 'removed' : 'would remove', $recordId, $recordDate, $docName), 'warning');
            $report['removed'][] = [
                'pohodaId' => $recordId,
                'docId' => (int) $docId,
                'date' => $recordDate,
                'was' => $docName,
                'reason' => 'Wrong-account link with no SharePoint PDF for this date',
            ];
        }
    } catch (\Exception $exc) {
        $logger->addStatusMessage(sprintf('Error on record #%d: %s', $recordId, $exc->getMessage()), 'error');
        $report['errors'][] = sprintf('Error for record #%d: %s', $recordId, $exc->getMessage());

        if ($exitcode === 0) {
            $exitcode = 3;
        }
    }
}

$logger->addStatusMessage(sprintf('Done (%s): %d fixed, %d corrected, %d removed, %d ok, %d skipped, %d xml moved, %d errors', $apply ? 'applied' : 'dry-run', \count($report['fixed']), \count($report['corrected']), \count($report['removed']), $report['ok'], \count($report['skipped']), \count($report['xml_moved']), \count($report['errors'])), $exitcode === 0 ? 'success' : 'warning');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$logger->addStatusMessage(sprintf('Saving result to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
