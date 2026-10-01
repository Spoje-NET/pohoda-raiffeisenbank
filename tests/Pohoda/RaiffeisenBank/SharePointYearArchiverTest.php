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

namespace Test\Pohoda\RaiffeisenBank;

use Pohoda\RaiffeisenBank\SharePointYearArchiver;

class SharePointYearArchiverTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = [
        '194_2024_630804003_3780381_CZK_2024-11-06.pdf' => 'id-2024-pdf',
        '194_2024_630804003_3780381_CZK_2024-11-06.xml' => 'id-2024-xml',
        '12_2023_630804003_3780381_CZK_2023-01-31.PDF' => 'id-2023-pdf',
        '1_2026_630804003_3780381_CZK_2026-01-02.pdf' => 'id-2026-pdf',
        'notes.txt' => 'id-txt',
        'report.pdf' => 'id-nodate',
        'statement_2022-05-05.docx' => 'id-docx',
    ];

    /**
     * @var list<array{string, string, string}>
     */
    private array $calls = [];

    public function testSelectFilesSkipsCurrentYearAndNonStatements(): void
    {
        self::assertSame([
            '194_2024_630804003_3780381_CZK_2024-11-06.pdf' => ['year' => '2024', 'ref' => 'id-2024-pdf'],
            '194_2024_630804003_3780381_CZK_2024-11-06.xml' => ['year' => '2024', 'ref' => 'id-2024-xml'],
            '12_2023_630804003_3780381_CZK_2023-01-31.PDF' => ['year' => '2023', 'ref' => 'id-2023-pdf'],
        ], SharePointYearArchiver::selectFiles(self::FILES, '2026'));
    }

    public function testSelectFilesTakesYearFromDateNotFromPrefix(): void
    {
        // Statement numbered within 2024 but dated in 2025 belongs to 2025.
        self::assertSame(
            ['5_2024_630804003_CZK_2025-01-01.xml' => ['year' => '2025', 'ref' => 'x']],
            SharePointYearArchiver::selectFiles(['5_2024_630804003_CZK_2025-01-01.xml' => 'x'], '2026'),
        );
    }

    public function testSelectFilesHonoursTargetYear(): void
    {
        self::assertSame(
            ['12_2023_630804003_3780381_CZK_2023-01-31.PDF' => ['year' => '2023', 'ref' => 'id-2023-pdf']],
            SharePointYearArchiver::selectFiles(self::FILES, '2026', '2023'),
        );
    }

    public function testSelectFilesToleratesIntegerKeys(): void
    {
        self::assertSame([], SharePointYearArchiver::selectFiles([12345 => 'ref'], '2026'));
    }

    public function testDryRunReportsButNeverTouchesSharePoint(): void
    {
        $report = $this->makeArchiver(apply: false)->run();

        self::assertSame([], $this->calls);
        self::assertSame(0, $report['exitcode']);
        self::assertFalse($report['apply']);
        self::assertSame(['2023', '2024'], $report['years_created']);
        self::assertCount(3, $report['moved']);
        self::assertSame([
            'filename' => '194_2024_630804003_3780381_CZK_2024-11-06.pdf',
            'year' => '2024',
            'from' => 'Sdilene dokumenty/Banky',
            'to' => 'Sdilene dokumenty/Archiv/2024',
        ], $report['moved'][0]);
        self::assertSame([], $report['errors']);
    }

    public function testApplyEnsuresEachYearOnceAndMovesEveryFile(): void
    {
        $report = $this->makeArchiver(apply: true)->run();

        self::assertSame([
            ['ensure', '2023', ''],
            ['ensure', '2024', ''],
            ['move', 'id-2024-pdf', '2024'],
            ['move', 'id-2024-xml', '2024'],
            ['move', 'id-2023-pdf', '2023'],
        ], $this->calls);
        self::assertSame(0, $report['exitcode']);
        self::assertSame(['2023', '2024'], $report['years_created']);
        self::assertCount(3, $report['moved']);
    }

    public function testTrailingSlashInArchivePrefixDoesNotDoubleSlash(): void
    {
        $report = $this->makeArchiver(archivePrefix: 'Sdilene dokumenty/Archiv/')->run();

        self::assertSame('Sdilene dokumenty/Archiv/2024', $report['moved'][0]['to']);
    }

    public function testNothingToArchive(): void
    {
        $report = $this->makeArchiver(apply: true, files: ['1_2026_X_CZK_2026-03-01.pdf' => 'a'])->run();

        self::assertSame([], $this->calls);
        self::assertSame(0, $report['exitcode']);
        self::assertSame([], $report['moved']);
    }

    public function testListingFailureExitsWithOne(): void
    {
        $report = $this->makeArchiver(apply: true, listError: new \RuntimeException('boom'))->run();

        self::assertSame(1, $report['exitcode']);
        self::assertCount(1, $report['errors']);
        self::assertStringContainsString('SharePoint file listing', $report['errors'][0]);
        self::assertSame([], $this->calls);
    }

    public function testFolderFailureExitsWithThree(): void
    {
        $report = $this->makeArchiver(apply: true, failEnsureYear: '2023')->run();

        self::assertSame(3, $report['exitcode']);
        self::assertSame(['2024'], $report['years_created']);
        self::assertStringContainsString('Ensuring archive folder for 2023', $report['errors'][0]);
    }

    public function testSingleMoveFailureDoesNotStopOtherMoves(): void
    {
        $report = $this->makeArchiver(apply: true, failMoveRef: 'id-2024-xml')->run();

        self::assertSame(3, $report['exitcode']);
        self::assertCount(2, $report['moved']);
        self::assertSame(
            ['194_2024_630804003_3780381_CZK_2024-11-06.pdf', '12_2023_630804003_3780381_CZK_2023-01-31.PDF'],
            array_column($report['moved'], 'filename'),
        );
        self::assertStringContainsString('Move of 194_2024_630804003_3780381_CZK_2024-11-06.xml', $report['errors'][0]);
    }

    /**
     * @param null|array<string, string> $files
     */
    private function makeArchiver(
        bool $apply = false,
        string $archivePrefix = 'Sdilene dokumenty/Archiv',
        ?array $files = null,
        ?\Exception $listError = null,
        ?string $failEnsureYear = null,
        ?string $failMoveRef = null,
    ): SharePointYearArchiver {
        $files ??= self::FILES;

        return new SharePointYearArchiver(
            static function () use ($files, $listError): array {
                if ($listError !== null) {
                    throw $listError;
                }

                return $files;
            },
            function (string $year) use ($failEnsureYear): void {
                if ($year === $failEnsureYear) {
                    throw new \RuntimeException('cannot create folder');
                }

                $this->calls[] = ['ensure', $year, ''];
            },
            function (string $ref, string $year) use ($failMoveRef): void {
                if ($ref === $failMoveRef) {
                    throw new \RuntimeException('cannot move');
                }

                $this->calls[] = ['move', $ref, $year];
            },
            'Sdilene dokumenty/Banky',
            $archivePrefix,
            '2026',
            '',
            $apply,
        );
    }
}
