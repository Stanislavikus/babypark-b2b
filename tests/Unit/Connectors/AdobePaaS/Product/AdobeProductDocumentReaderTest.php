<?php

namespace Tests\Unit\Connectors\AdobePaaS\Product;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocument;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReadException;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReader;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdobeProductDocumentReaderTest extends TestCase
{
    #[Test]
    public function document_preserves_complete_payload_and_reads_top_level_custom_and_nested_values(): void
    {
        $document = AdobeProductDocument::fromPayload([
            'id' => 321,
            'sku' => 'SKU-321',
            'type_id' => 'simple',
            'name' => 'Blue Product',
            'price' => 0,
            'custom_attributes' => [
                ['attribute_code' => 'color', 'value' => '42'],
                ['attribute_code' => 'nullable_attr', 'value' => null],
            ],
            'extension_attributes' => [
                'stock_item' => ['qty' => 7.5],
            ],
        ]);

        self::assertSame(321, $document->logicalEntityId);
        self::assertSame('SKU-321', $document->sku);
        self::assertSame('simple', $document->typeId);
        self::assertSame('Blue Product', $document->originalPayload['name']);
        self::assertSame(['present' => true, 'value' => 'Blue Product'], $document->externalValue('name'));
        self::assertSame(['present' => true, 'value' => 0], $document->externalValue('price'));
        self::assertSame(['present' => true, 'value' => '42'], $document->externalValue('color'));
        self::assertSame(['present' => true, 'value' => 7.5], $document->externalValue('extension_attributes.stock_item.qty'));
    }

    #[Test]
    public function document_distinguishes_present_null_from_absent(): void
    {
        $document = AdobeProductDocument::fromPayload([
            'id' => 321,
            'sku' => 'SKU-321',
            'type_id' => 'simple',
            'custom_attributes' => [
                ['attribute_code' => 'nullable_attr', 'value' => null],
            ],
            'extension_attributes' => [
                'stock_item' => ['backorders' => null],
            ],
        ]);

        self::assertSame(['present' => true, 'value' => null], $document->externalValue('nullable_attr'));
        self::assertSame(['present' => true, 'value' => null], $document->externalValue('extension_attributes.stock_item.backorders'));
        self::assertSame(['present' => false, 'value' => null], $document->externalValue('missing'));
        self::assertSame(['present' => false, 'value' => null], $document->externalValue('extension_attributes.stock_item.qty'));
    }

    #[Test]
    public function reader_reuses_existing_stock_get_transport_seam_without_duplicate_get_stack(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public ?ConnectorOutboundRequest $lastRequest = null;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->lastRequest = $request;

                return new ConnectorHttpResult(
                    200,
                    ['Content-Type' => ['application/json']],
                    json_encode([
                        'id' => 321,
                        'sku' => 'SKU/Blue 42',
                        'type_id' => 'simple',
                        'name' => 'Blue Product',
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => '42'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                );
            }
        };

        $reader = new AdobeProductDocumentReader(
            $this->contextFactory(),
            new AdobeProductRemoteStateClient(
                $this->contextFactory(),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
        );

        $document = $reader->readWithContext($this->context(), 'SKU/Blue 42');

        self::assertSame('GET', $transport->lastRequest?->request->getMethod());
        self::assertSame(
            'https://shop.example.com/rest/default/V1/products/SKU%2FBlue%2042',
            (string) $transport->lastRequest?->request->getUri(),
        );
        self::assertStringContainsString('OAuth ', $transport->lastRequest?->request->getHeaderLine('Authorization') ?? '');
        self::assertSame(['present' => true, 'value' => '42'], $document->externalValue('color'));
        self::assertFileDoesNotExist($this->repoPath('app/Support/Connectors/AdobePaaS/Product/AdobeProductReadRequestFactory.php'));
        self::assertFileDoesNotExist($this->repoPath('app/Support/Connectors/AdobePaaS/Product/AdobeProductReadClient.php'));

        $readerSource = file_get_contents($this->repoPath('app/Support/Connectors/AdobePaaS/Product/AdobeProductDocumentReader.php'));
        self::assertStringNotContainsString('GuzzleHttp\\Client', $readerSource);
        self::assertStringNotContainsString('new Client(', $readerSource);
    }

    #[Test]
    public function malformed_or_non_object_response_fails_safely(): void
    {
        $this->expectException(AdobeProductDocumentReadException::class);
        $this->expectExceptionMessage('Magento Product document read must return a JSON object.');

        $reader = new AdobeProductDocumentReader(
            $this->contextFactory(),
            new AdobeProductRemoteStateClient(
                $this->contextFactory(),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                new class implements ConnectorHttpTransport
                {
                    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
                    {
                        return new ConnectorHttpResult(200, [], '[]');
                    }
                },
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
        );

        $reader->readWithContext($this->context(), 'SKU-321');
    }

    #[Test]
    public function transport_ambiguity_fails_closed_without_retry_logic(): void
    {
        $this->expectException(AdobeProductDocumentReadException::class);
        $this->expectExceptionMessage('Magento Product document read transport failed.');

        $reader = new AdobeProductDocumentReader(
            $this->contextFactory(),
            new AdobeProductRemoteStateClient(
                $this->contextFactory(),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                new class implements ConnectorHttpTransport
                {
                    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
                    {
                        throw new ConnectorTransportException(TransportFailureReason::Timeout);
                    }
                },
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
        );

        $reader->readWithContext($this->context(), 'SKU-321');
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }

    private function contextFactory(): AdobePaaSRequestContextFactory
    {
        return new AdobePaaSRequestContextFactory();
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 5).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
