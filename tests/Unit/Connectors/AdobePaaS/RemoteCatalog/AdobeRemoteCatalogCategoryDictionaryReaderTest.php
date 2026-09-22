<?php

namespace Tests\Unit\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogHttpCategoryDictionaryReader;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadException;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;

class AdobeRemoteCatalogCategoryDictionaryReaderTest extends TestCase
{
    #[Test]
    public function it_reads_categories_in_bounded_pages_and_builds_breadcrumbs(): void
    {
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return match ($count) {
                1 => $this->json([
                    'items' => [
                        ['id' => 1, 'parent_id' => 0, 'name' => 'Root', 'level' => 0, 'path' => '1'],
                        ['id' => 3, 'parent_id' => 1, 'name' => 'Store Root', 'level' => 1, 'path' => '1/3'],
                    ],
                    'total_count' => 3,
                ]),
                2 => $this->json([
                    'items' => [
                        ['id' => 6, 'parent_id' => 3, 'name' => 'Strollers', 'level' => 2, 'path' => '1/3/6'],
                    ],
                    'total_count' => 3,
                ]),
                default => throw new \RuntimeException('Unexpected category request.'),
            };
        });

        $reader = new AdobeRemoteCatalogHttpCategoryDictionaryReader(
            new AdobeRemoteCatalogRequestFactory(new OAuth1RequestSigner),
            $transport,
        );

        $paths = $reader->read($this->context());

        $this->assertSame('', $paths['3']);
        $this->assertSame('Strollers', $paths['6']);
        $this->assertSame(2, $transport->sendCount);
        $this->assertStringContainsString('/V1/categories/list', (string) $transport->recordedRequests[0]->request->getUri());
        $this->assertStringContainsString('pageSize%5D=100', (string) $transport->recordedRequests[0]->request->getUri());
        $this->assertStringContainsString('currentPage%5D=2', (string) $transport->recordedRequests[1]->request->getUri());
    }

    #[Test]
    public function it_fails_if_category_total_changes_between_pages(): void
    {
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return $count === 1
                ? $this->json([
                    'items' => [['id' => 1, 'parent_id' => 0, 'name' => 'Root', 'level' => 0, 'path' => '1']],
                    'total_count' => 2,
                ])
                : $this->json([
                    'items' => [['id' => 2, 'parent_id' => 1, 'name' => 'Changed', 'level' => 1, 'path' => '1/2']],
                    'total_count' => 3,
                ]);
        });

        $reader = new AdobeRemoteCatalogHttpCategoryDictionaryReader(
            new AdobeRemoteCatalogRequestFactory(new OAuth1RequestSigner),
            $transport,
        );

        $this->expectException(AdobeRemoteCatalogReadException::class);
        $this->expectExceptionMessage('total_count changed');

        $reader->read($this->context());
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            'https://shop.example.com',
            'default',
            new OAuth1Credentials('ck', 'cs', 'at', 'ts'),
        );
    }

    private function json(array $payload): ConnectorHttpResult
    {
        return new ConnectorHttpResult(
            200,
            ['content-type' => ['application/json']],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
