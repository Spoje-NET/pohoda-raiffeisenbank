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
 * Move stale bank statement files out of the working SharePoint folder into
 * per-year subfolders, so the working folder only ever contains the current
 * year's statements.
 *
 * Every PDF/XML statement whose embedded date does not fall in CURRENT_YEAR
 * is relocated to "<archive prefix>/<that file's year>" (e.g. ".../2024",
 * ".../2023", ...), sorted by each file's own year rather than dumped into a
 * single archive bucket, so older statements stay easy to find for audits.
 *
 * Set TARGET_YEAR to restrict a run to a single year (e.g. archive just 2023
 * without touching 2022, 2021, ...) instead of every out-of-CURRENT_YEAR file
 * at once.
 */
$options = getopt('o::e:', ['output::environment:']);
Shared::init(
    ['OFFICE365_TENANT', 'OFFICE365_PATH', 'OFFICE365_SITE'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$currentYear = (string) Shared::cfg('CURRENT_YEAR', date('Y'));
$targetYear = trim((string) Shared::cfg('TARGET_YEAR', ''));
$archivePrefix = rtrim((string) Shared::cfg('OFFICE365_ARCHIVE_PATH', Shared::cfg('OFFICE365_PATH')), '/');

if ($targetYear !== '' && !preg_match('/^[0-9]{4}$/', $targetYear)) {
    fwrite(\STDERR, sprintf('Invalid TARGET_YEAR "%s": expected a 4-digit year'.\PHP_EOL, $targetYear));

    exit(2);
}

// When false (default) the tool only reports what it WOULD move and creates
// nothing — run once in this dry-run mode, review, then set true.
$apply = filter_var(Shared::cfg('ARCHIVE_APPLY', false), \FILTER_VALIDATE_BOOLEAN);

$exitcode = 0;
$report = [
    'path' => Shared::cfg('OFFICE365_PATH'),
    'archive_prefix' => $archivePrefix,
    'current_year' => $currentYear,
    'target_year' => $targetYear !== '' ? $targetYear : null,
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
            $files[$spFile->getName()] = $spFile->getServerRelativeUrl();
        }

        return $files;
    };
    $doEnsureFolder = static function (string $year) use ($ctx, $resetAuth, $archivePrefix): void {
        PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($ctx, $archivePrefix, $year) {
            // Adding a folder that already exists is a no-op on SharePoint's
            // side (returns the existing folder), so this is safe to call
            // every run without checking existence first.
            $ctx->getWeb()->getFolders()->add(rtrim($archivePrefix, '/').'/'.$year);

            return $ctx->executeQuery();
        });
    };
    $doMove = static function (string $sourceUrl, string $year) use ($ctx, $resetAuth, $archivePrefix): void {
        PohodaBankClientOffice::withSharePointRetry($ctx, $resetAuth, static function () use ($ctx, $sourceUrl, $archivePrefix, $year) {
            $filename = basename($sourceUrl);
            $destUrl = rtrim($archivePrefix, '/').'/'.$year.'/'.$filename;
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

    $doList = static function () use ($graph, $path): array {
        $files = [];

        foreach ($graph->listFilesDetailed($path) as $name => $item) {
            $files[$name] = $item['id'];
        }

        return $files;
    };
    $doEnsureFolder = static function (string $year) use ($graph, $archivePrefix): void {
        $graph->ensureFolder(rtrim($archivePrefix, '/').'/'.$year);
    };
    $doMove = static function (string $itemId, string $year) use ($graph, $archivePrefix): void {
        $graph->moveFile($itemId, rtrim($archivePrefix, '/').'/'.$year);
    };
}

// filename => ['year' => 'YYYY', 'ref' => server-relative URL (legacy) or item id (Graph)]
$filesToMove = [];

try {
    $sharepointFiles = $doList();

    foreach ($sharepointFiles as $name => $ref) {
        // PHP silently coerces purely-numeric string array keys to int, so
        // $name isn't reliably a string here even though both $doList()
        // branches build it from a filename - cast back before using it.
        $name = (string) $name;

        if (!preg_match('/(\d{4})-\d{2}-\d{2}\.(?:pdf|xml)$/i', $name, $dateMatch)) {
            continue;
        }

        $year = $dateMatch[1];

        if ($year === $currentYear) {
            continue;
        }

        if ($targetYear !== '' && $year !== $targetYear) {
            continue;
        }

        $filesToMove[$name] = ['year' => $year, 'ref' => (string) $ref];
    }

    $logger->addStatusMessage(sprintf('Found %d file(s) to archive out of %d listed%s', \count($filesToMove), \count($sharepointFiles), $targetYear !== '' ? ' (restricted to TARGET_YEAR '.$targetYear.')' : ''), 'info');
} catch (\Exception $exc) {
    $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint file listing');
    $logger->addStatusMessage($errorMessage, 'error');
    $report['errors'][] = $errorMessage;
    $exitcode = 1;
}

if (empty($filesToMove)) {
    $logger->addStatusMessage($targetYear !== ''
        ? sprintf('No files found for TARGET_YEAR %s — nothing to archive.', $targetYear)
        : 'No files outside CURRENT_YEAR found — nothing to archive.', $exitcode === 0 ? 'success' : 'warning');
    $report['exitcode'] = $exitcode;
    file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));

    exit($exitcode);
}

// Stage 2: Ensure each needed year subfolder exists (once per year, not once
// per file), then move every matched file into it.
$logger->addStatusMessage('stage 2/2: '.($apply ? 'Archiving' : 'Evaluating (dry-run)').' files by year', 'debug');

$years = array_unique(array_column($filesToMove, 'year'));
sort($years);

foreach ($years as $year) {
    if ($apply) {
        try {
            $doEnsureFolder($year);
            $report['years_created'][] = $year;
        } catch (\Exception $exc) {
            $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'Ensuring archive folder for '.$year);
            $logger->addStatusMessage($errorMessage, 'error');
            $report['errors'][] = $errorMessage;
            $exitcode = 3;
        }
    } else {
        $report['years_created'][] = $year;
    }
}

foreach ($filesToMove as $filename => $info) {
    $year = $info['year'];
    $target = rtrim($archivePrefix, '/').'/'.$year;

    try {
        if ($apply) {
            $doMove($info['ref'], $year);
        }

        $logger->addStatusMessage(sprintf('%s %s → %s', $apply ? 'moved' : 'would move', $filename, $target), 'success');
        $report['moved'][] = [
            'filename' => $filename,
            'year' => $year,
            'from' => Shared::cfg('OFFICE365_PATH'),
            'to' => $target,
        ];
    } catch (\Exception $exc) {
        $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'Move of '.$filename);
        $logger->addStatusMessage($errorMessage, 'error');
        $report['errors'][] = $errorMessage;

        if ($exitcode === 0) {
            $exitcode = 3;
        }
    }
}

$logger->addStatusMessage(sprintf('Done (%s): %d moved, %d error(s)', $apply ? 'applied' : 'dry-run', \count($report['moved']), \count($report['errors'])), $exitcode === 0 ? 'success' : 'warning');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$logger->addStatusMessage(sprintf('Saving result to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
