<?php

namespace Tests\Unit\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\Dns\ConnectorDnsResolution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDnsResolutionTest extends TestCase
{
    #[Test]
    public function interpret_records_rejects_duplicate_terminal_addresses_with_different_ipv6_spellings(): void
    {
        $records = [
            [
                'type' => 'AAAA',
                'ipv6' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            ],
            [
                'type' => 'AAAA',
                'ipv6' => '2001:db8::1',
            ],
        ];

        $result = ConnectorDnsResolution::interpretRecords($records);

        $this->assertSame('error', $result['type']);
        $this->assertSame('invalid_record', $result['reason']);
    }

    #[Test]
    public function resolve_hostname_with_injected_lookup_rejects_duplicate_terminal_addresses(): void
    {
        $result = ConnectorDnsResolution::resolveHostname(
            'dup.example.com',
            static fn (string $absoluteName): array => [
                [
                    'type' => 'AAAA',
                    'host' => 'dup.example.com',
                    'ipv6' => '2001:0db8:0000:0000:0000:0000:0000:0001',
                ],
                [
                    'type' => 'AAAA',
                    'host' => 'dup.example.com',
                    'ipv6' => '2001:db8::1',
                ],
            ],
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid_record', $result['reason']);
    }

    #[Test]
    public function resolve_hostname_with_injected_lookup_returns_protocol_v1_ok_envelope(): void
    {
        $result = ConnectorDnsResolution::resolveHostname(
            'public.example.com',
            static fn (string $absoluteName): array => [
                [
                    'type' => 'A',
                    'host' => 'public.example.com',
                    'ip' => '93.184.216.34',
                ],
            ],
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('public.example.com', $result['requested_hostname']);
        $this->assertSame([], $result['cname_chain']);
        $this->assertSame('93.184.216.34', $result['terminal']['addresses'][0]['address']);
    }
}
