<?php

namespace Tests\Unit\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\OAuth1\AssertsOAuth1SecretsSafely;

class AdobeProductCommandRequestFactoryTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;

    private AdobeProductCommandRequestFactory $factory;

    private AdobePaaSRequestContext $context;

    private OAuth1SigningContext $signingContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new AdobeProductCommandRequestFactory(new OAuth1RequestSigner);
        $this->context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
        $this->signingContext = new OAuth1SigningContext('abc123nonce', 1_700_000_000);
    }

    #[Test]
    public function get_request_uses_store_scoped_product_path_and_oauth_signature(): void
    {
        $request = $this->factory->buildGet($this->context, 'SKU/1', $this->signingContext);
        $expectedUrl = 'https://shop.example.com/rest/default/V1/products/SKU%2F1';
        $expectedAuthorization = (new OAuth1RequestSigner)->sign(
            'GET',
            $expectedUrl,
            null,
            null,
            $this->context->credentials,
            $this->signingContext,
        );

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame($expectedUrl, (string) $request->getUri());
        self::assertSameOAuth1AuthorizationHeader($expectedAuthorization, $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function post_request_uses_product_envelope_and_json_body(): void
    {
        $desired = $this->desiredState();
        $request = $this->factory->buildPost($this->context, $desired, $this->signingContext);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/products',
            (string) $request->getUri(),
        );
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('SKU-TEST-1', $payload['product']['sku']);
        $this->assertSame('simple', $payload['product']['type_id']);
        $this->assertSame(100, $payload['product']['price']);
    }

    #[Test]
    public function put_request_targets_sku_path_with_product_envelope(): void
    {
        $desired = $this->desiredState();
        $request = $this->factory->buildPut($this->context, $desired, $this->signingContext);

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/products/SKU-TEST-1',
            (string) $request->getUri(),
        );

        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('product', $payload);
        $this->assertSame('SKU-TEST-1', $payload['product']['sku']);
    }

    private function desiredState(): AdobeProductDesiredState
    {
        return new AdobeProductDesiredState(
            productVariantId: 'variant-1',
            sku: 'SKU-TEST-1',
            name: 'Test Product',
            attributeSetId: 4,
            typeId: 'simple',
            status: 1,
            visibility: 4,
            price: 100.0,
            priceCurrency: 'UAH',
            customAttributes: [],
        );
    }
}
