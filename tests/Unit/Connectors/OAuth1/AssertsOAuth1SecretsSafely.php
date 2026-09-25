<?php

namespace Tests\Unit\Connectors\OAuth1;

use PHPUnit\Framework\Assert;

trait AssertsOAuth1SecretsSafely
{
    protected static function assertSameOAuth1Signature(string $expected, string $actual): void
    {
        Assert::assertTrue(
            hash_equals($expected, $actual),
            'OAuth signature did not match the approved golden fixture.',
        );
    }

    protected static function assertSameOAuth1AuthorizationHeader(string $expected, string $actual): void
    {
        Assert::assertTrue(
            hash_equals($expected, $actual),
            'OAuth Authorization header did not match the approved golden fixture.',
        );
    }
}
