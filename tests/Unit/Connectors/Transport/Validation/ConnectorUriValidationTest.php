<?php

namespace Tests\Unit\Connectors\Transport\Validation;

use App\Support\Connectors\Transport\Validation\HostnameGrammar;
use App\Support\Connectors\Transport\Validation\IpLiteralGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorUriValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('validIpv4Provider')]
    public function accepts_canonical_ipv4_literals(string $literal, string $expected): void
    {
        $this->assertSame($expected, IpLiteralGrammar::tryParse($literal));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validIpv4Provider(): array
    {
        return [
            'canonical' => ['192.0.2.1', '192.0.2.1'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedAmbiguousFormsProvider')]
    public function rejects_ambiguous_numeric_forms(string $value): void
    {
        $this->assertNull(IpLiteralGrammar::tryParse($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedAmbiguousFormsProvider(): array
    {
        return [
            'decimal integer' => ['2130706433'],
            'hex' => ['0x7f000001'],
            'shortened' => ['127.1'],
            'octal' => ['0177.0.0.1'],
            'leading zero octet' => ['092.0.0.1'],
        ];
    }

    #[Test]
    public function accepts_equivalent_ipv6_forms(): void
    {
        $expanded = IpLiteralGrammar::tryParse('2001:0db8:0000:0000:0000:0000:0000:0001');
        $compressed = IpLiteralGrammar::tryParse('2001:db8::1');

        $this->assertNotNull($expanded);
        $this->assertSame($expanded, $compressed);
    }

    #[Test]
    #[DataProvider('invalidHostnameProvider')]
    public function rejects_invalid_hostnames(string $hostname): void
    {
        $this->assertFalse(HostnameGrammar::isValid($hostname));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidHostnameProvider(): array
    {
        return [
            'punycode' => ['xn--example.com'],
            'trailing dot' => ['example.com.'],
            'underscore' => ['bad_label.example.com'],
            'leading hyphen' => ['-bad.example.com'],
        ];
    }
}
