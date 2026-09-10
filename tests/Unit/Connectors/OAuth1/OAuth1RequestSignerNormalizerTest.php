<?php

namespace Tests\Unit\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\OAuth1MediaType;
use App\Support\Connectors\OAuth1\OAuth1ParameterNormalizer;
use App\Support\Connectors\OAuth1\OAuth1ParameterPair;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuth1RequestSignerNormalizerTest extends TestCase
{
    private OAuth1ParameterNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new OAuth1ParameterNormalizer;
    }

    #[Test]
    public function sorts_by_encoded_name_not_unencoded_name(): void
    {
        $pairs = [
            new OAuth1ParameterPair('b', '1'),
            new OAuth1ParameterPair('á', '2'),
        ];

        $normalized = $this->normalizer->normalize($pairs);

        $this->assertSame('%C3%A1=2&b=1', $normalized);
    }

    #[Test]
    public function form_urlencoded_content_type_variants_are_recognized(): void
    {
        $this->assertTrue(OAuth1MediaType::isFormUrlEncoded('application/x-www-form-urlencoded'));
        $this->assertTrue(OAuth1MediaType::isFormUrlEncoded('Application/X-Www-Form-Urlencoded'));
        $this->assertTrue(OAuth1MediaType::isFormUrlEncoded('application/x-www-form-urlencoded; charset=UTF-8'));
        $this->assertFalse(OAuth1MediaType::isFormUrlEncoded('application/json'));
    }
}
