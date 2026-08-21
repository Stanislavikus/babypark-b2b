<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\OAuth1\AssertsOAuth1SecretsSafely;

class AdobeSafeSyncRequestFactoryTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;

    private AdobeSafeSyncRequestFactory $factory;

    private AdobePaaSRequestContext $context;

    private OAuth1SigningContext $signingContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new AdobeSafeSyncRequestFactory(new OAuth1RequestSigner);
        $this->context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
        $this->signingContext = new OAuth1SigningContext('abc123nonce', 1_700_000_000);
    }

    #[Test]
    public function handshake_request_uses_safe_sync_endpoint_with_oauth_signature(): void
    {
        $request = $this->factory->buildHandshake($this->context, $this->signingContext);
        $expectedUrl = 'https://shop.example.com/rest/default/V1/safe-sync/handshake';
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
    public function product_read_request_targets_entity_bound_safe_sync_path_not_stock_product_get(): void
    {
        $request = $this->factory->buildReadProduct($this->context, 123, 'SKU/1', $this->signingContext);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/products/123?expectedSku=SKU%2F1',
            (string) $request->getUri(),
        );
        $this->assertStringNotContainsString('/V1/products/SKU%2F1', (string) $request->getUri());
    }
}
