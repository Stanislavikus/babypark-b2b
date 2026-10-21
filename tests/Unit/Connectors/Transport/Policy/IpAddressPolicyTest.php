<?php

namespace Tests\Unit\Connectors\Transport\Policy;

use App\Support\Connectors\Transport\Policy\IpAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IpAddressPolicyTest extends TestCase
{
    private IpAddressPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new IpAddressPolicy;
    }

    #[Test]
    #[DataProvider('rejectedAddressesProvider')]
    public function rejects_special_purpose_addresses(string $address): void
    {
        $this->assertFalse($this->policy->isGloballyReachable($address));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedAddressesProvider(): array
    {
        return [
            'shared address space' => ['100.64.0.1'],
            'link-local v4' => ['169.254.1.1'],
            'benchmarking' => ['198.18.0.1'],
            'documentation v4' => ['192.0.2.1'],
            'reserved v4' => ['240.0.0.1'],
            'unique-local v6' => ['fc00::1'],
            'link-local v6' => ['fe80::1'],
            'documentation v6' => ['2001:db8::1'],
            'discard-only v6' => ['100::1'],
            'multicast v6' => ['ff02::1'],
            'ipv4-mapped loopback bypass' => ['::ffff:127.0.0.1'],
            'alternate ipv4-mapped loopback' => ['0:0:0:0:0:ffff:127.0.0.1'],
            'loopback v4' => ['127.0.0.1'],
            'private v4' => ['10.0.0.1'],
        ];
    }

    #[Test]
    public function accepts_globally_reachable_ipv4_and_ipv6(): void
    {
        $this->assertTrue($this->policy->isGloballyReachable('93.184.216.34'));
        $this->assertTrue($this->policy->isGloballyReachable('2606:2800:220:1:248:1893:25c8:1946'));
    }

    #[Test]
    public function longest_prefix_match_allows_more_specific_exception(): void
    {
        $this->assertTrue($this->policy->isGloballyReachable('192.0.0.128'));
        $this->assertFalse($this->policy->isGloballyReachable('192.0.0.1'));
        $this->assertFalse($this->policy->isGloballyReachable('192.0.2.1'));
    }

    #[Test]
    public function normalizes_ipv6_textual_variants_to_same_address(): void
    {
        $a = $this->policy->normalize('2606:2800:0220:0001:0248:1893:25c8:1946');
        $b = $this->policy->normalize('2606:2800:220:1:248:1893:25c8:1946');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function deterministic_pinning_sorts_ipv4_before_ipv6(): void
    {
        $sorted = $this->policy->filterAndSortReachable([
            '2606:2800:220:1:248:1893:25c8:1946',
            '93.184.216.34',
        ]);

        $this->assertSame('93.184.216.34', $sorted[0]);
    }
}
