<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\RetryAfterHeaderNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetryAfterHeaderNormalizerTest extends TestCase
{
    #[Test]
    public function parses_delta_seconds(): void
    {
        $this->assertSame(60, RetryAfterHeaderNormalizer::normalize(['Retry-After' => ['60']]));
        $this->assertSame(300, RetryAfterHeaderNormalizer::normalize(['Retry-After' => ['999']]));
    }

    #[Test]
    public function rejects_non_digit_delta_seconds(): void
    {
        $this->assertNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => [' 60']]));
        $this->assertNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => ['-1']]));
        $this->assertNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => ['1.5']]));
    }

    #[Test]
    public function parses_http_date_and_rounds_up(): void
    {
        $future = gmdate('D, d M Y H:i:s', time() + 3).' GMT';

        $this->assertGreaterThanOrEqual(1, RetryAfterHeaderNormalizer::normalize(['Retry-After' => [$future]]));
    }

    #[Test]
    public function past_http_date_resolves_to_null(): void
    {
        $past = gmdate('D, d M Y H:i:s', time() - 60).' GMT';

        $this->assertNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => [$past]]));
    }

    #[Test]
    public function multiple_values_resolve_to_null(): void
    {
        $this->assertNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => ['60', '120']]));
    }

    #[Test]
    public function missing_header_resolves_to_null(): void
    {
        $this->assertNull(RetryAfterHeaderNormalizer::normalize([]));
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function malformed_values_resolve_to_null(array $headers): void
    {
        $this->assertNull(RetryAfterHeaderNormalizer::normalize($headers));
    }

    /**
     * @return iterable<string, array{0: array<string, list<string>>}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'invalid date' => [['Retry-After' => ['not-a-date']]];
    }

    #[Test]
    public function http_date_with_comma_parses_without_splitting(): void
    {
        $future = gmdate('D, d M Y H:i:s', time() + 120).' GMT';

        $this->assertNotNull(RetryAfterHeaderNormalizer::normalize(['Retry-After' => [$future]]));
    }
}
