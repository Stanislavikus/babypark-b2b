<?php

namespace Tests\Unit\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;
use App\Support\Connectors\OAuth1\OAuth1BaseStringUriBuilder;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1FormUrlEncodedParser;
use App\Support\Connectors\OAuth1\OAuth1MediaType;
use App\Support\Connectors\OAuth1\OAuth1ParameterNormalizer;
use App\Support\Connectors\OAuth1\OAuth1ParameterPair;
use App\Support\Connectors\OAuth1\OAuth1PercentEncoder;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1RequestUrl;
use App\Support\Connectors\OAuth1\OAuth1SignatureBaseStringBuilder;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuth1RequestSignerTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;

    private OAuth1RequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new OAuth1RequestSigner;
    }

    #[Test]
    public function golden_fixture_matches_phase_a_values(): void
    {
        $method = 'GET';
        $url = 'https://shop.example.com/rest/default/V1/products/attributes?searchCriteria[pageSize]=1';
        $credentials = new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test');
        $context = new OAuth1SigningContext('abc123nonce', 1_700_000_000);

        $intermediate = $this->buildIntermediateValues(
            $method,
            $url,
            null,
            null,
            $credentials,
            $context,
        );

        $this->assertSame(
            'oauth_consumer_key=ck_test&oauth_nonce=abc123nonce&oauth_signature_method=HMAC-SHA256&oauth_timestamp=1700000000&oauth_token=at_test&oauth_version=1.0&searchCriteria%5BpageSize%5D=1',
            $intermediate['normalizedParameterString'],
        );
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/products/attributes',
            $intermediate['baseStringUri'],
        );
        $this->assertSame(
            'GET&https%3A%2F%2Fshop.example.com%2Frest%2Fdefault%2FV1%2Fproducts%2Fattributes&oauth_consumer_key%3Dck_test%26oauth_nonce%3Dabc123nonce%26oauth_signature_method%3DHMAC-SHA256%26oauth_timestamp%3D1700000000%26oauth_token%3Dat_test%26oauth_version%3D1.0%26searchCriteria%255BpageSize%255D%3D1',
            $intermediate['signatureBaseString'],
        );
        self::assertSameOAuth1Signature('PhzkFN03dKikBE2qOkNfTQce2N0eNh1jUZhXwxTZHog=', $intermediate['signature']);

        $authorizationHeader = $this->signer->sign($method, $url, null, null, $credentials, $context);

        self::assertSameOAuth1AuthorizationHeader(
            'OAuth oauth_consumer_key="ck_test", oauth_nonce="abc123nonce", oauth_signature="PhzkFN03dKikBE2qOkNfTQce2N0eNh1jUZhXwxTZHog=", oauth_signature_method="HMAC-SHA256", oauth_timestamp="1700000000", oauth_token="at_test", oauth_version="1.0"',
            $authorizationHeader,
        );
    }

    #[Test]
    public function empty_token_secret_uses_trailing_ampersand_in_signing_key(): void
    {
        $credentials = new OAuth1Credentials('ck_test', 'cs_test', 'at_test', '');
        $context = new OAuth1SigningContext('nonce', 1_700_000_000);

        $intermediate = $this->buildIntermediateValues(
            'GET',
            'https://shop.example.com/rest/default/V1/products',
            null,
            null,
            $credentials,
            $context,
        );

        $this->assertSame('cs_test&', $intermediate['signingKey']);
    }

    #[Test]
    public function form_urlencoded_body_parameters_are_included_in_signing(): void
    {
        $credentials = $this->defaultCredentials();
        $context = new OAuth1SigningContext('nonce', 1_700_000_000);

        $intermediate = $this->buildIntermediateValues(
            'POST',
            'https://shop.example.com/rest/default/V1/products',
            'application/x-www-form-urlencoded',
            'field=value&field=two',
            $credentials,
            $context,
        );

        $this->assertSame(
            'field=two&field=value&oauth_consumer_key=ck_test&oauth_nonce=nonce&oauth_signature_method=HMAC-SHA256&oauth_timestamp=1700000000&oauth_token=at_test&oauth_version=1.0',
            $intermediate['normalizedParameterString'],
        );
    }

    #[Test]
    public function json_body_fields_are_never_included_even_when_names_overlap_with_query_parameters(): void
    {
        $credentials = $this->defaultCredentials();
        $context = new OAuth1SigningContext('nonce', 1_700_000_000);

        $intermediate = $this->buildIntermediateValues(
            'POST',
            'https://shop.example.com/rest/default/V1/products?field=query',
            'application/json',
            '{"field":"body"}',
            $credentials,
            $context,
        );

        $this->assertSame(
            'field=query&oauth_consumer_key=ck_test&oauth_nonce=nonce&oauth_signature_method=HMAC-SHA256&oauth_timestamp=1700000000&oauth_token=at_test&oauth_version=1.0',
            $intermediate['normalizedParameterString'],
        );
    }

    #[Test]
    public function caller_supplied_oauth_query_parameter_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);
        $this->expectExceptionMessage('Caller-supplied OAuth protocol parameters are not permitted.');

        $this->signer->sign(
            'GET',
            'https://shop.example.com/path?oauth_consumer_key=evil',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function caller_supplied_oauth_body_parameter_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);
        $this->expectExceptionMessage('Caller-supplied OAuth protocol parameters are not permitted.');

        $this->signer->sign(
            'POST',
            'https://shop.example.com/path',
            'application/x-www-form-urlencoded',
            'oauth_token=evil',
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function relative_url_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);

        $this->signer->sign(
            'GET',
            '/relative/path',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function missing_scheme_or_host_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);

        $this->signer->sign(
            'GET',
            'https:///path',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function unsupported_scheme_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);

        $this->signer->sign(
            'GET',
            'ftp://shop.example.com/path',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function userinfo_in_authority_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);

        $this->signer->sign(
            'GET',
            'https://user:pass@shop.example.com/path',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function invalid_port_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);

        $this->signer->sign(
            'GET',
            'https://shop.example.com:70000/path',
            null,
            null,
            $this->defaultCredentials(),
            new OAuth1SigningContext('nonce', 1_700_000_000),
        );
    }

    #[Test]
    public function uppercase_method_is_normalized_for_signing(): void
    {
        $credentials = $this->defaultCredentials();
        $context = new OAuth1SigningContext('nonce', 1_700_000_000);

        $lower = $this->buildIntermediateValues('get', 'https://shop.example.com/path', null, null, $credentials, $context);
        $upper = $this->buildIntermediateValues('GET', 'https://shop.example.com/path', null, null, $credentials, $context);

        $this->assertSame($upper['signatureBaseString'], $lower['signatureBaseString']);
    }

    /**
     * @return array{
     *     normalizedParameterString: string,
     *     baseStringUri: string,
     *     signatureBaseString: string,
     *     signingKey: string,
     *     signature: string,
     * }
     */
    private function buildIntermediateValues(
        string $method,
        string $absoluteUrl,
        ?string $contentType,
        ?string $rawBody,
        OAuth1Credentials $credentials,
        OAuth1SigningContext $context,
    ): array {
        $parser = new OAuth1FormUrlEncodedParser;
        $requestUrl = OAuth1RequestUrl::parse($absoluteUrl);
        $pairs = $parser->parse($requestUrl->rawQuery);

        if (OAuth1MediaType::isFormUrlEncoded($contentType)) {
            $pairs = array_merge($pairs, $parser->parse($rawBody ?? ''));
        }

        $oauthParameters = [
            'oauth_consumer_key' => $credentials->consumerKey,
            'oauth_token' => $credentials->accessToken,
            'oauth_nonce' => $context->nonce,
            'oauth_timestamp' => (string) $context->timestamp,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_version' => '1.0',
        ];

        $signingPairs = array_merge(
            $pairs,
            array_map(
                static fn (string $name, string $value): OAuth1ParameterPair => new OAuth1ParameterPair($name, $value),
                array_keys($oauthParameters),
                array_values($oauthParameters),
            ),
        );

        $normalizer = new OAuth1ParameterNormalizer;
        $normalizedParameterString = $normalizer->normalize($signingPairs);
        $baseStringUri = (new OAuth1BaseStringUriBuilder)->build($requestUrl);
        $signatureBaseString = (new OAuth1SignatureBaseStringBuilder)->build(
            $method,
            $baseStringUri,
            $normalizedParameterString,
        );

        $signingKey = OAuth1PercentEncoder::encode($credentials->consumerSecret)
            .'&'
            .OAuth1PercentEncoder::encode($credentials->accessTokenSecret);
        $signature = base64_encode(hash_hmac('sha256', $signatureBaseString, $signingKey, true));

        return [
            'normalizedParameterString' => $normalizedParameterString,
            'baseStringUri' => $baseStringUri,
            'signatureBaseString' => $signatureBaseString,
            'signingKey' => $signingKey,
            'signature' => $signature,
        ];
    }

    private function defaultCredentials(): OAuth1Credentials
    {
        return new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test');
    }
}
