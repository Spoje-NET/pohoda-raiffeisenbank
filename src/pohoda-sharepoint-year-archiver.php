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

\define('APP_NAME', 'PohodaSharepointYearArchiver');

require_once '../vendor/autoload.php';

/**
 * Sort bank statement PDFs/XMLs out of the working SharePoint folder into
 * per-year subfolders, so accountants only see the current year's statements
 * in OFFICE365_PATH while older years stay reachable (grouped by year) for
 * the occasional audit lookup.
 */
$options = getopt('o::e:', ['output::environment:']);
Shared::init(
    ['OFFICE365_TENANT', 'OFFICE365_PATH', 'OFFICE365_SITE'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$currentYear = (string) Shared::cfg('CURRENT_YEAR', date('Y'));
$archivePrefix = rtrim((string) Shared::cfg('OFFICE365_ARCHIVE_PATH', Shared::cfg('OFFICE365_PATH')), '/');

// When false (default) the tool only reports which files WOULD move and
// where — run once in this dry-run mode, review, then set true.
$apply = filter_var(Shared::cfg('ARCHIVE_APPLY', false), \FILTER_VALIDATE_BOOLEAN);

$exitcode = 0;
$report = [
    'path' => Shared::cfg('OFFICE365_PATH'),
    'archive_path' => $archivePrefix,
    'current_year' => $currentYear,
    'apply' => $apply,
    'years_created' => [],
    'moved' => [],
    'skipped' => [],
    'errors' => [],
];

$logger = new \Ease\Sand();
$logger->setObjectName('PohodaSharepointYearArchiver');

if (Shared::cfg('APP_DEBUG', false)) {
    $logger->logBanner(APP_NAME, sprintf('Path: %s | Archive prefix: %s | Current year: %s | %s', Shared::cfg('OFFICE365_PATH'), $archivePrefix, $currentYear, $apply ? 'APPLY' : 'DRY-RUN'));
}

// Stage 1: Connect to SharePoint and list all files in the working folder.
$logger->addStatusMessage('stage 1/2: Listing SharePoint statement files', 'debug');

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

        foreach ($sharepointFilesRaw as $spFile) {
            $files[$spFile->getName()] = true;
        }

        return $files;
    };
    $ensuredFolders = [];
    $doEnsureYearFolder = static function (string $year) use ($ctx, $resetAuth, $archivePrefix, &$ensuredFolders): void {
        if (isset($ensuredFolders[$year])) {
            return;
        }

        PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($ctx, $archivePrefix, $year) {
            $ctx->getWeb()->getFolders()->add($archivePrefix.'/'.$year);

            return $ctx->executeQuery();
        });
        $ensuredFolders[$year] = true;
    };
    $doMove = static function (string $filename, string $year) use ($ctx, $resetAuth, $archivePrefix): void {
        PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($ctx, $filename, $year, $archivePrefix) {
            $sourceUrl = rtrim(Shared::cfg('OFFICE365_PATH'), '/').'/'.$filename;
            $destUrl = $archivePrefix.'/'.$year.'/'.$filename;
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

    $itemIds = [];
    $doList = static function () use ($graph, $path, &$itemIds): array {
        $files = [];

        foreach ($graph->listFilesDetailed($path) as $name => $item) {
            $files[$name] = true;
            $itemIds[$name] = $item['id'];
        }

        return $files;
    };
    $ensuredFolders = [];
    $doEnsureYearFolder = static function (string $year) use ($graph, $archivePrefix, &$ensuredFolders): void {
        if (isset($ensuredFolders[$year])) {
            return;
        }

        $graph->ensureFolder($archivePrefix.'/'.$year);
        $ensuredFolders[$year] = true;
    };
    $doMove = static function (string $filename, string $year) use ($graph, $archivePrefix, &$itemIds): void {
        $graph->moveFile($itemIds[$filename], $archivePrefix.'/'.$year);
    };
}

// year => [filenames...] of files to relocate. Filename pattern:
// {statNum}_{account}_{accountId}_{currency}_{YYYY-MM-DD}.{pdf|xml}
$byYear = [];

try {
    $sharepointFiles = $doList();

    foreach ($sharepointFiles as $name => $_) {
        // PHP silently coerces purely-numeric string array keys to int (e.g.
        // a SharePoint item literally named "20260821"), so $name isn't
        // reliably a string here even though it was built from a filename -
        // cast back before using it as one.
        $name = (string) $name;

        if (!preg_match('/(\d{4})-\d{2}-\d{2}\.(pdf|xml)$/i', $name, $match)) {
            continue;
        }

        $year = $match[1];

        if ($year === $currentYear) {
            continue;
        }

        $byYear[$year][] = $name;
        $logger->addStatusMessage(sprintf('%s %s (year %s)', $apply ? 'Will move' : 'Would move', $name, $year), 'debug');
    }

    $logger->addStatusMessage(sprintf('Found %d file(s) across %d year(s) to archive', array_sum(array_map('count', $byYear)), \count($byYear)), 'info');
} catch (\Exception $exc) {
    $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint file listing');
    $logger->addStatusMessage($errorMessage, 'error');
    $report['errors'][] = $errorMessage;
    $exitcode = 1;
}

if (empty($byYear)) {
    $logger->addStatusMessage('No out-of-year statement files found — nothing to archive.', 'warning');
    $report['exitcode'] = $exitcode;
    file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));

    exit($exitcode);
}

// Stage 2: For each year present, ensure the archive subfolder exists, then
// move that year's files into it.
$logger->addStatusMessage(sprintf('stage 2/2: %s files into per-year folders under %s', $apply ? 'Moving' : 'Evaluating (dry-run)', $archivePrefix), 'debug');

foreach ($byYear as $year => $filenames) {
    // $byYear's keys came from a numeric regex capture ("2024"), which PHP
    // silently coerces to int when used as an array key - cast back to
    // string before passing to the string-typed closures below, or
    // declare(strict_types=1) throws a TypeError.
    $year = (string) $year;

    try {
        if ($apply) {
            $doEnsureYearFolder($year);
        }

        $report['years_created'][] = $year;
    } catch (\Exception $exc) {
        $errorMessage = PohodaBankClientOffice::describeRequestException($exc, sprintf('Ensuring archive folder for year %s', $year));
        $logger->addStatusMessage($errorMessage, 'error');
        $report['errors'][] = $errorMessage;

        if ($exitcode === 0) {
            $exitcode = 3;
        }

        continue;
    }

    foreach ($filenames as $filename) {
        try {
            if ($apply) {
                $doMove($filename, $year);
            }

            $logger->addStatusMessage(sprintf('%s %s → %s/%s', $apply ? 'moved' : 'would move', $filename, $archivePrefix, $year), 'success');
            $report['moved'][] = [
                'filename' => $filename,
                'year' => $year,
                'from' => Shared::cfg('OFFICE365_PATH'),
                'to' => $archivePrefix.'/'.$year,
            ];
        } catch (\Exception $exc) {
            $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'Archive move of '.$filename);
            $logger->addStatusMessage($errorMessage, 'error');
            $report['errors'][] = $errorMessage;

            if ($exitcode === 0) {
                $exitcode = 3;
            }
        }
    }
}

$logger->addStatusMessage(sprintf('Done (%s): %d moved across %d year(s), %d errors', $apply ? 'applied' : 'dry-run', \count($report['moved']), \count($report['years_created']), \count($report['errors'])), $exitcode === 0 ? 'success' : 'warning');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$logger->addStatusMessage(sprintf('Saving result to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
