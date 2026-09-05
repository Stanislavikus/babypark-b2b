<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\OAuth1\AssertsOAuth1SecretsSafely;

class AdobePaaSConnectionCheckRequestFactoryTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;

    private AdobePaaSConnectionCheckRequestFactory $factory;

    private OAuth1Credentials $credentials;

    private OAuth1SigningContext $signingContext;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->factory = new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner);
        $this->credentials = new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test');
        $this->signingContext = new OAuth1SigningContext('abc123nonce', 1_700_000_000);
    }

    #[Test]
    public function builds_signed_connection_check_request_matching_signer_output(): void
    {
        $context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: $this->credentials,
        );

        $request = $this->factory->build($context, $this->signingContext);
        $expectedUrl = 'https://shop.example.com/rest/default/V1/products/attributes?searchCriteria%5BpageSize%5D=1';
        $expectedAuthorization = (new OAuth1RequestSigner)->sign(
            'GET',
            $expectedUrl,
            null,
            null,
            $this->credentials,
            $this->signingContext,
        );

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame($expectedUrl, (string) $request->getUri());
        $this->assertCount(1, $request->getHeader('Authorization'));
        self::assertSameOAuth1AuthorizationHeader($expectedAuthorization, $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function builds_signed_products_search_request_matching_signer_output(): void
    {
        $context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: $this->credentials,
        );

        $request = $this->factory->buildProductsSearch($context, $this->signingContext, ['pageSize' => 1]);
        $expectedUrl = 'https://shop.example.com/rest/default/V1/products?searchCriteria%5BpageSize%5D=1';
        $expectedAuthorization = (new OAuth1RequestSigner)->sign(
            'GET',
            $expectedUrl,
            null,
            null,
            $this->credentials,
            $this->signingContext,
        );

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame($expectedUrl, (string) $request->getUri());
        $this->assertCount(1, $request->getHeader('Authorization'));
        self::assertSameOAuth1AuthorizationHeader($expectedAuthorization, $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function base_url_with_and_without_trailing_slash_produce_identical_endpoint_url(): void
    {
        $withoutSlash = $this->factory->build(
            new AdobePaaSRequestContext('https://shop.example.com', 'default', $this->credentials),
            $this->signingContext,
        );
        $withSlash = $this->factory->build(
            new AdobePaaSRequestContext('https://shop.example.com/', 'default', $this->credentials),
            $this->signingContext,
        );

        $this->assertSame((string) $withoutSlash->getUri(), (string) $withSlash->getUri());
    }

    #[Test]
    public function base_url_with_application_root_path_prefix_is_preserved(): void
    {
        $withoutSlash = $this->factory->build(
            new AdobePaaSRequestContext('https://commerce.example.test/magento', 'default', $this->credentials),
            $this->signingContext,
        );
        $withSlash = $this->factory->build(
            new AdobePaaSRequestContext('https://commerce.example.test/magento/', 'default', $this->credentials),
            $this->signingContext,
        );

        $expectedUrl = 'https://commerce.example.test/magento/rest/default/V1/products/attributes?searchCriteria%5BpageSize%5D=1';

        $this->assertSame($expectedUrl, (string) $withoutSlash->getUri());
        $this->assertSame($expectedUrl, (string) $withSlash->getUri());
    }

    #[Test]
    public function base_url_with_query_string_is_rejected(): void
    {
        $this->expectException(InvalidAdobePaaSRequestContextException::class);
        $this->expectExceptionMessage('Adobe PaaS base URL must not contain a query string or fragment.');

        $this->factory->build(
            new AdobePaaSRequestContext('https://shop.example.com?foo=bar', 'default', $this->credentials),
            $this->signingContext,
        );
    }

    #[Test]
    public function base_url_with_fragment_is_rejected(): void
    {
        $this->expectException(InvalidAdobePaaSRequestContextException::class);
        $this->expectExceptionMessage('Adobe PaaS base URL must not contain a query string or fragment.');

        $this->factory->build(
            new AdobePaaSRequestContext('https://shop.example.com#fragment', 'default', $this->credentials),
            $this->signingContext,
        );
    }

    #[Test]
    public function empty_store_code_is_rejected(): void
    {
        $this->expectException(InvalidAdobePaaSRequestContextException::class);
        $this->expectExceptionMessage('Adobe PaaS store code must not be empty.');

        $this->factory->build(
            new AdobePaaSRequestContext('https://shop.example.com', '', $this->credentials),
            $this->signingContext,
        );
    }

    #[Test]
    public function store_code_cannot_escape_path_segment_via_scheme_or_leading_slash(): void
    {
        $maliciousStoreCodes = [
            '://evil.example.test',
            '/evil.example.test',
            'default/../../admin',
            '%2Fevil.example.test',
        ];

        foreach ($maliciousStoreCodes as $storeCode) {
            $request = $this->factory->build(
                new AdobePaaSRequestContext('https://shop.example.com', $storeCode, $this->credentials),
                $this->signingContext,
            );

            $uri = $request->getUri();

            $this->assertSame('shop.example.com', $uri->getHost(), 'Store code must not change host for ['.$storeCode.'].');
            $this->assertStringStartsWith(
                'https://shop.example.com/rest/'.rawurlencode($storeCode).'/V1/products/attributes',
                (string) $uri,
                'Store code must remain a single encoded path segment for ['.$storeCode.'].',
            );
        }
    }

    #[Test]
    public function built_request_host_matches_context_base_url_host(): void
    {
        $request = $this->factory->build(
            new AdobePaaSRequestContext('https://commerce.example.test/magento', 'default', $this->credentials),
            $this->signingContext,
        );

        $this->assertSame('commerce.example.test', $request->getUri()->getHost());
    }

    #[Test]
    public function exception_messages_do_not_leak_secrets(): void
    {
        $secrets = ['cs_test', 'ts_test'];

        try {
            $this->factory->build(
                new AdobePaaSRequestContext('https://shop.example.com?secret=cs_test', 'default', $this->credentials),
                $this->signingContext,
            );
        } catch (InvalidAdobePaaSRequestContextException $exception) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }
}
