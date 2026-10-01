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

/**
 * Core of pohoda-sharepoint-year-archiver: selects statement files that fall
 * outside CURRENT_YEAR and moves them into "<archive prefix>/<year>".
 *
 * SharePoint access is injected as closures (legacy SharePoint REST or
 * Microsoft Graph - see the script), so the selection/move logic can be
 * tested without network access.
 */
final class SharePointYearArchiver
{
    /**
     * @param \Closure(): array<string, string>     $doList         filename => server-relative URL (legacy) or item id (Graph)
     * @param \Closure(string): void                $doEnsureFolder creates "<archive prefix>/<year>" if missing
     * @param \Closure(string, string): void        $doMove         moves file ref into "<archive prefix>/<year>"
     */
    public function __construct(
        private readonly \Closure $doList,
        private readonly \Closure $doEnsureFolder,
        private readonly \Closure $doMove,
        private readonly string $path,
        private readonly string $archivePrefix,
        private readonly string $currentYear,
        private readonly string $targetYear = '',
        private readonly bool $apply = false,
        private ?\Ease\Sand $logger = null,
    ) {
        if ($this->logger === null) {
            $this->logger = new \Ease\Sand();
            $this->logger->setObjectName('PohodaSharepointYearArchiver');
        }
    }

    /**
     * Pick the statement files that should be archived.
     *
     * @param array<int|string, string> $files filename => ref
     *
     * @return array<string, array{year: string, ref: string}>
     */
    public static function selectFiles(array $files, string $currentYear, string $targetYear = ''): array
    {
        $filesToMove = [];

        foreach ($files as $name => $ref) {
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

        return $filesToMove;
    }

    /**
     * List, then (in apply mode) create year folders and move files.
     *
     * @return array<string, mixed> report, including 'exitcode' (0 ok, 1 listing failed, 3 folder/move failed)
     */
    public function run(): array
    {
        $exitcode = 0;
        $report = [
            'path' => $this->path,
            'archive_prefix' => $this->archivePrefix,
            'current_year' => $this->currentYear,
            'target_year' => $this->targetYear !== '' ? $this->targetYear : null,
            'apply' => $this->apply,
            'years_created' => [],
            'moved' => [],
            'skipped' => [],
            'errors' => [],
        ];

        // Stage 1: list all files in the working folder.
        $this->logger->addStatusMessage('stage 1/2: Listing SharePoint statement files', 'debug');
        $filesToMove = [];

        try {
            $sharepointFiles = ($this->doList)();
            $filesToMove = self::selectFiles($sharepointFiles, $this->currentYear, $this->targetYear);
            $this->logger->addStatusMessage(sprintf('Found %d file(s) to archive out of %d listed%s', \count($filesToMove), \count($sharepointFiles), $this->targetYear !== '' ? ' (restricted to TARGET_YEAR '.$this->targetYear.')' : ''), 'info');
        } catch (\Exception $exc) {
            $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'SharePoint file listing');
            $this->logger->addStatusMessage($errorMessage, 'error');
            $report['errors'][] = $errorMessage;
            $exitcode = 1;
        }

        if (empty($filesToMove)) {
            $this->logger->addStatusMessage($this->targetYear !== ''
                ? sprintf('No files found for TARGET_YEAR %s — nothing to archive.', $this->targetYear)
                : 'No files outside CURRENT_YEAR found — nothing to archive.', $exitcode === 0 ? 'success' : 'warning');
            $report['exitcode'] = $exitcode;

            return $report;
        }

        // Stage 2: Ensure each needed year subfolder exists (once per year, not
        // once per file), then move every matched file into it.
        $this->logger->addStatusMessage('stage 2/2: '.($this->apply ? 'Archiving' : 'Evaluating (dry-run)').' files by year', 'debug');

        $years = array_unique(array_column($filesToMove, 'year'));
        sort($years);

        foreach ($years as $year) {
            if ($this->apply) {
                try {
                    ($this->doEnsureFolder)($year);
                    $report['years_created'][] = $year;
                } catch (\Exception $exc) {
                    $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'Ensuring archive folder for '.$year);
                    $this->logger->addStatusMessage($errorMessage, 'error');
                    $report['errors'][] = $errorMessage;
                    $exitcode = 3;
                }
            } else {
                $report['years_created'][] = $year;
            }
        }

        foreach ($filesToMove as $filename => $info) {
            $year = $info['year'];
            $target = rtrim($this->archivePrefix, '/').'/'.$year;

            try {
                if ($this->apply) {
                    ($this->doMove)($info['ref'], $year);
                }

                $this->logger->addStatusMessage(sprintf('%s %s → %s', $this->apply ? 'moved' : 'would move', $filename, $target), 'success');
                $report['moved'][] = [
                    'filename' => $filename,
                    'year' => $year,
                    'from' => $this->path,
                    'to' => $target,
                ];
            } catch (\Exception $exc) {
                $errorMessage = PohodaBankClientOffice::describeRequestException($exc, 'Move of '.$filename);
                $this->logger->addStatusMessage($errorMessage, 'error');
                $report['errors'][] = $errorMessage;

                if ($exitcode === 0) {
                    $exitcode = 3;
                }
            }
        }

        $this->logger->addStatusMessage(sprintf('Done (%s): %d moved, %d error(s)', $this->apply ? 'applied' : 'dry-run', \count($report['moved']), \count($report['errors'])), $exitcode === 0 ? 'success' : 'warning');

        $report['exitcode'] = $exitcode;

        return $report;
    }
}
