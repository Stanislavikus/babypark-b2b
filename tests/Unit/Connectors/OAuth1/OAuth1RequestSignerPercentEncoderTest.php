<?php

namespace Tests\Unit\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\OAuth1PercentEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuth1RequestSignerPercentEncoderTest extends TestCase
{
    #[Test]
    public function encodes_space_as_percent_twenty(): void
    {
        $this->assertSame('%20', OAuth1PercentEncoder::encode(' '));
    }

    #[Test]
    public function encodes_plus_as_percent_two_b(): void
    {
        $this->assertSame('%2B', OAuth1PercentEncoder::encode('+'));
    }

    #[Test]
    public function leaves_tilde_unescaped(): void
    {
        $this->assertSame('~', OAuth1PercentEncoder::encode('~'));
    }

    #[Test]
    public function encodes_percent_sign(): void
    {
        $this->assertSame('%25', OAuth1PercentEncoder::encode('%'));
    }

    #[Test]
    public function encodes_unicode_characters(): void
    {
        $this->assertSame('%D0%BA%D0%B8%D1%97%D0%B2', OAuth1PercentEncoder::encode('київ'));
    }
}
