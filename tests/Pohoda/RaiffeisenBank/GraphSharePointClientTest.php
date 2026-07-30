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

    /**
     * @param array<string, string> $responsesByUrl
     */
    private function makeClientWithFakeResponses(array $responsesByUrl, ?\Closure $onRequest = null): GraphSharePointClient
    {
        $authContext = new EntraIdAppOnlyAuthenticationContext('contoso', 'client-id', 'client-secret', 'https://graph.microsoft.com/.default');
        $onRequest ??= static function (): void {};

        return new class('contoso', 'Team', $authContext, $responsesByUrl, $onRequest) extends GraphSharePointClient {
            /**
             * @param array<string, string> $responsesByUrl
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

                return $this->responsesByUrl[$url];
            }
        };
    }
}
