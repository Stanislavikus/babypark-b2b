<?php

namespace Tests\Unit\Connectors\AdobePaaS\Product;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocument;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductReadClient;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductReadException;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductReadRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductTransportTest extends TestCase
{
    #[Test]
    public function request_factory_reads_stock_magento_product_endpoint_with_oauth1(): void
    {
        $context = $this->context();
        $signingContext = new OAuth1SigningContext('nonce-123', 1_700_000_000);
        $factory = new AdobeProductReadRequestFactory(new OAuth1RequestSigner);

        $request = $factory->build($context, 'SKU/Blue 42', $signingContext);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/products/SKU%2FBlue%2042',
            (string) $request->getUri(),
        );
        $this->assertStringContainsString('OAuth ', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('oauth_consumer_key="ck_test"', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function product_document_preserves_complete_payload_and_resolves_core_custom_and_nested_fields(): void
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
        ], 321, 'SKU-321');

        $this->assertSame(['present' => true, 'value' => 'Blue Product'], $document->externalValue('name'));
        $this->assertSame(['present' => true, 'value' => 0], $document->externalValue('price'));
        $this->assertSame(['present' => true, 'value' => '42'], $document->externalValue('color'));
        $this->assertSame(['present' => true, 'value' => null], $document->externalValue('nullable_attr'));
        $this->assertSame(['present' => true, 'value' => 7.5], $document->externalValue('extension_attributes.stock_item.qty'));
        $this->assertSame(['present' => false, 'value' => null], $document->externalValue('missing'));
    }

    #[Test]
    public function read_client_returns_verified_complete_product_document(): void
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
                        'sku' => 'SKU-321',
                        'type_id' => 'simple',
                        'name' => 'Blue Product',
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => '42'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                );
            }
        };

        $client = new AdobeProductReadClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductReadRequestFactory(new OAuth1RequestSigner),
            $transport,
        );

        $document = $client->readWithContext($this->context(), 321, 'SKU-321');

        $this->assertSame(321, $document->logicalEntityId);
        $this->assertSame('SKU-321', $document->sku);
        $this->assertSame('simple', $document->typeId);
        $this->assertSame(['present' => true, 'value' => '42'], $document->externalValue('color'));
        $this->assertNotNull($transport->lastRequest);
        $this->assertSame('GET', $transport->lastRequest->request->getMethod());
    }

    #[Test]
    public function read_client_fails_closed_on_identity_mismatch(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(
                    200,
                    [],
                    '{"id":999,"sku":"SKU-321","type_id":"simple"}',
                );
            }
        };

        $client = new AdobeProductReadClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductReadRequestFactory(new OAuth1RequestSigner),
            $transport,
        );

        $this->expectException(AdobeProductReadException::class);
        $this->expectExceptionMessage('Magento Product logical entity identity mismatch.');

        $client->readWithContext($this->context(), 321, 'SKU-321');
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }
}
