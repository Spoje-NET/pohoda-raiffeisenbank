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

use Pohoda\RaiffeisenBank\EntraIdAppOnlyAuthenticationContext;
use Pohoda\RaiffeisenBank\GraphApiException;
use Pohoda\RaiffeisenBank\GraphSharePointClient;

class GraphSharePointClientTest extends \PHPUnit\Framework\TestCase
{
    public function testCreateShareLinkReturnsPermanentWebUrl(): void
    {
        $client = $this->makeClientWithFakeResponses([
            'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
            'https://graph.microsoft.com/v1.0/sites/site-id/drive/items/item-42/createLink' => '{"link":{"webUrl":"https://contoso.sharepoint.com/:w:/r/permanent-link"}}',
        ]);

        self::assertSame(
            'https://contoso.sharepoint.com/:w:/r/permanent-link',
            $client->createShareLink('item-42', 'view', 'organization'),
        );
    }

    public function testCreateShareLinkSendsRequestedTypeAndScope(): void
    {
        $capturedBody = null;

        $client = $this->makeClientWithFakeResponses(
            [
                'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/items/item-42/createLink' => '{"link":{"webUrl":"https://contoso.sharepoint.com/:w:/r/edit-link"}}',
            ],
            static function (string $method, string $url, ?string $body) use (&$capturedBody): void {
                if (str_contains($url, '/createLink')) {
                    $capturedBody = $body;
                }
            },
        );

        $client->createShareLink('item-42', 'edit', 'anonymous');

        self::assertSame(['type' => 'edit', 'scope' => 'anonymous'], json_decode((string) $capturedBody, true));
    }

    public function testListFilesDetailedReturnsIdAndWebUrl(): void
    {
        $client = $this->makeClientWithFakeResponses([
            'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
            'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky:/children?$select=id,name,webUrl' => '{"value":[{"id":"item-1","name":"statement.pdf","webUrl":"https://contoso.sharepoint.com/sites/Team/statement.pdf"}]}',
        ]);

        self::assertSame(
            ['statement.pdf' => ['id' => 'item-1', 'webUrl' => 'https://contoso.sharepoint.com/sites/Team/statement.pdf']],
            $client->listFilesDetailed('Sdilene dokumenty/Banky'),
        );
    }

    public function testListFilesDelegatesToListFilesDetailed(): void
    {
        $client = $this->makeClientWithFakeResponses([
            'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
            'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky:/children?$select=id,name,webUrl' => '{"value":[{"id":"item-1","name":"statement.pdf","webUrl":"https://contoso.sharepoint.com/sites/Team/statement.pdf"}]}',
        ]);

        self::assertSame(
            ['statement.pdf' => 'https://contoso.sharepoint.com/sites/Team/statement.pdf'],
            $client->listFiles('Sdilene dokumenty/Banky'),
        );
    }

    public function testMoveFileSendsParentReferencePath(): void
    {
        $capturedBody = null;

        $client = $this->makeClientWithFakeResponses(
            [
                'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/items/item-42' => '{"id":"item-42","name":"statement.xml","webUrl":"https://contoso.sharepoint.com/sites/Team/XML/statement.xml"}',
            ],
            static function (string $method, string $url, ?string $body) use (&$capturedBody): void {
                if ($method === 'PATCH') {
                    $capturedBody = $body;
                }
            },
        );

        $result = $client->moveFile('item-42', 'Sdilene dokumenty/Banky/XML');

        self::assertSame(
            ['parentReference' => ['path' => '/drive/root:/Banky/XML']],
            json_decode((string) $capturedBody, true),
        );
        self::assertSame('https://contoso.sharepoint.com/sites/Team/XML/statement.xml', $result['webUrl']);
    }

    public function testMoveFileIncludesNewNameWhenGiven(): void
    {
        $capturedBody = null;

        $client = $this->makeClientWithFakeResponses(
            [
                'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/items/item-42' => '{"id":"item-42"}',
            ],
            static function (string $method, string $url, ?string $body) use (&$capturedBody): void {
                if ($method === 'PATCH') {
                    $capturedBody = $body;
                }
            },
        );

        $client->moveFile('item-42', 'Sdilene dokumenty/Banky/XML', 'renamed.xml');

        self::assertSame(
            ['parentReference' => ['path' => '/drive/root:/Banky/XML'], 'name' => 'renamed.xml'],
            json_decode((string) $capturedBody, true),
        );
    }

    public function testEnsureFolderCreatesOnlyMissingSegments(): void
    {
        $requests = [];

        $client = $this->makeClientWithFakeResponses(
            [
                'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky' => '{"id":"banky-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky/XML' => new GraphApiException('not found', 404),
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky:/children' => '{"id":"xml-id","name":"XML"}',
            ],
            static function (string $method, string $url) use (&$requests): void {
                $requests[] = $method.' '.$url;
            },
        );

        $client->ensureFolder('Sdilene dokumenty/Banky/XML');

        self::assertSame(
            [
                'GET https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team',
                'GET https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky',
                'GET https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky/XML',
                'POST https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky:/children',
            ],
            $requests,
        );
    }

    public function testEnsureFolderCreatesNothingWhenFullPathAlreadyExists(): void
    {
        $requests = [];

        $client = $this->makeClientWithFakeResponses(
            [
                'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
                'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky' => '{"id":"banky-id"}',
            ],
            static function (string $method, string $url) use (&$requests): void {
                $requests[] = $method.' '.$url;
            },
        );

        $client->ensureFolder('Sdilene dokumenty/Banky');

        self::assertSame(
            [
                'GET https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team',
                'GET https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky',
            ],
            $requests,
        );
    }

    public function testEnsureFolderTreatsCreateConflictAsSuccess(): void
    {
        $client = $this->makeClientWithFakeResponses([
            'https://graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/Team' => '{"id":"site-id"}',
            'https://graph.microsoft.com/v1.0/sites/site-id/drive/root:/Banky' => new GraphApiException('not found', 404),
            'https://graph.microsoft.com/v1.0/sites/site-id/drive/root/children' => new GraphApiException('already exists', 409),
        ]);

        // A concurrent process created it between the 404 check and our create - no exception should escape.
        $client->ensureFolder('Sdilene dokumenty/Banky');

        self::assertTrue(true);
    }

    /**
     * @param array<string, \Throwable|string> $responsesByUrl
     */
    private function makeClientWithFakeResponses(array $responsesByUrl, ?\Closure $onRequest = null): GraphSharePointClient
    {
        $authContext = new EntraIdAppOnlyAuthenticationContext('contoso', 'client-id', 'client-secret', 'https://graph.microsoft.com/.default');
        $onRequest ??= static function (): void {};

        return new class('contoso', 'Team', $authContext, $responsesByUrl, $onRequest) extends GraphSharePointClient {
            /**
             * @param array<string, \Throwable|string> $responsesByUrl
             */
            public function __construct(
                string $tenant,
                string $site,
                EntraIdAppOnlyAuthenticationContext $authContext,
                private readonly array $responsesByUrl,
                private readonly ?\Closure $onRequest,
            ) {
                parent::__construct($tenant, $site, $authContext);
            }

            protected function request(string $method, string $url, ?string $body = null, ?string $contentType = null, bool $retried = false): string
            {
                ($this->onRequest)($method, $url, $body);

                if (!isset($this->responsesByUrl[$url])) {
                    throw new \RuntimeException("Unexpected request to {$url}");
                }

                $response = $this->responsesByUrl[$url];

                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        };
    }
}
