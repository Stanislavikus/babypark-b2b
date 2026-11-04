<?php

namespace Tests\Unit\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;
use App\Support\Connectors\OAuth1\OAuth1FormUrlEncodedParser;
use App\Support\Connectors\OAuth1\OAuth1ParameterNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuth1RequestSignerParserTest extends TestCase
{
    private OAuth1FormUrlEncodedParser $parser;

    private OAuth1ParameterNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OAuth1FormUrlEncodedParser;
        $this->normalizer = new OAuth1ParameterNormalizer;
    }

    #[Test]
    public function plus_decodes_to_space_and_reencodes_as_percent_twenty(): void
    {
        $normalized = $this->normalizeSingleQueryPair('x=+');

        $this->assertSame('x=%20', $normalized);
    }

    #[Test]
    public function percent_twenty_decodes_to_space_and_reencodes_as_percent_twenty(): void
    {
        $normalized = $this->normalizeSingleQueryPair('x=%20');

        $this->assertSame('x=%20', $normalized);
    }

    #[Test]
    public function percent_two_b_decodes_to_literal_plus_and_reencodes_as_percent_two_b(): void
    {
        $normalized = $this->normalizeSingleQueryPair('x=%2B');

        $this->assertSame('x=%2B', $normalized);
    }

    #[Test]
    public function plus_percent_twenty_and_percent_two_b_produce_distinct_normalized_outputs(): void
    {
        $fromPlus = $this->normalizeSingleQueryPair('x=+');
        $fromPercentTwenty = $this->normalizeSingleQueryPair('x=%20');
        $fromPercentTwoB = $this->normalizeSingleQueryPair('x=%2B');

        $this->assertSame('x=%20', $fromPlus);
        $this->assertSame('x=%20', $fromPercentTwenty);
        $this->assertSame('x=%2B', $fromPercentTwoB);
        $this->assertNotSame($fromPlus, $fromPercentTwoB);
        $this->assertNotSame($fromPercentTwenty, $fromPercentTwoB);
    }

    #[Test]
    public function duplicate_query_names_are_preserved(): void
    {
        $pairs = $this->parser->parse('foo=1&foo=2');
        $normalized = $this->normalizer->normalize($pairs);

        $this->assertSame('foo=1&foo=2', $normalized);
    }

    #[Test]
    public function more_than_two_values_for_the_same_name_are_preserved(): void
    {
        $pairs = $this->parser->parse('foo=1&foo=2&foo=3');
        $normalized = $this->normalizer->normalize($pairs);

        $this->assertSame('foo=1&foo=2&foo=3', $normalized);
    }

    #[Test]
    public function empty_parameter_value_is_preserved(): void
    {
        $normalized = $this->normalizeSingleQueryPair('empty=');

        $this->assertSame('empty=', $normalized);
    }

    #[Test]
    public function pair_without_equals_has_empty_value(): void
    {
        $pairs = $this->parser->parse('flag');
        $normalized = $this->normalizer->normalize($pairs);

        $this->assertSame('flag=', $normalized);
    }

    #[Test]
    public function value_may_contain_equals_after_the_first_separator(): void
    {
        $pairs = $this->parser->parse('equation=a=b');
        $normalized = $this->normalizer->normalize($pairs);

        $this->assertSame('equation=a%3Db', $normalized);
    }

    #[Test]
    public function malformed_percent_escape_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);
        $this->expectExceptionMessage('Malformed percent-escape in form or query parameter.');

        $this->parser->parse('x=%');
    }

    #[Test]
    public function incomplete_percent_escape_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);
        $this->expectExceptionMessage('Malformed percent-escape in form or query parameter.');

        $this->parser->parse('x=%2');
    }

    #[Test]
    public function non_hex_percent_escape_is_rejected(): void
    {
        $this->expectException(OAuth1StructuralException::class);
        $this->expectExceptionMessage('Malformed percent-escape in form or query parameter.');

        $this->parser->parse('x=%ZZ');
    }

    #[Test]
    public function malformed_percent_escape_message_does_not_echo_secret_like_values(): void
    {
        try {
            $this->parser->parse('oauth_consumer_key=secret&x=%ZZ');
            $this->fail('Expected OAuth1StructuralException was not thrown.');
        } catch (OAuth1StructuralException $exception) {
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertSame(
                'Malformed percent-escape in form or query parameter.',
                $exception->getMessage(),
            );
        }
    }

    private function normalizeSingleQueryPair(string $query): string
    {
        return $this->normalizer->normalize($this->parser->parse($query));
    }
}
