<?php

namespace Tests\Unit\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\Dns\DnsResponseParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DnsResponseParserTest extends TestCase
{
    private DnsResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DnsResponseParser;
    }

    #[Test]
    public function rejects_unknown_top_level_field(): void
    {
        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => [],
            'terminal' => [
                'owner' => 'api.example.com',
                'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
            ],
            'extra' => true,
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertFalse($result['success']);
        $this->assertTrue($result['protocolFailed']);
    }

    #[Test]
    public function rejects_invalid_version(): void
    {
        $json = json_encode([
            'version' => 2,
            'status' => 'error',
            'reason' => 'lookup_failed',
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['protocolFailed']);
    }

    #[Test]
    public function rejects_success_and_error_fields_together(): void
    {
        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'reason' => 'lookup_failed',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => [],
            'terminal' => [
                'owner' => 'api.example.com',
                'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['protocolFailed']);
    }

    #[Test]
    public function rejects_family_address_mismatch(): void
    {
        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => [],
            'terminal' => [
                'owner' => 'api.example.com',
                'addresses' => [['family' => 'ipv6', 'address' => '93.184.216.34']],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['protocolFailed']);
    }

    #[Test]
    public function aggregate_record_limit_allows_eight_cnames_and_fifty_six_addresses(): void
    {
        $addresses = [];
        for ($i = 1; $i <= 56; $i++) {
            $addresses[] = ['family' => 'ipv4', 'address' => '93.184.216.'.$i];
        }

        $cnameChain = [];
        for ($i = 0; $i < 8; $i++) {
            $owner = $i === 0 ? 'api.example.com' : "c{$i}.example.com";
            $target = 'c'.($i + 1).'.example.com';
            $cnameChain[] = ['owner' => $owner, 'target' => $target];
        }

        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => $cnameChain,
            'terminal' => [
                'owner' => 'c8.example.com',
                'addresses' => $addresses,
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function aggregate_record_limit_rejects_eight_cnames_and_fifty_seven_addresses(): void
    {
        $addresses = [];
        for ($i = 1; $i <= 57; $i++) {
            $addresses[] = ['family' => 'ipv4', 'address' => '93.184.216.'.$i];
        }

        $cnameChain = [];
        for ($i = 0; $i < 8; $i++) {
            $owner = $i === 0 ? 'api.example.com' : "c{$i}.example.com";
            $target = 'c'.($i + 1).'.example.com';
            $cnameChain[] = ['owner' => $owner, 'target' => $target];
        }

        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => $cnameChain,
            'terminal' => [
                'owner' => 'c8.example.com',
                'addresses' => $addresses,
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['protocolFailed']);
    }

    #[Test]
    public function rejects_semantically_incoherent_chain(): void
    {
        $json = json_encode([
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => 'api.example.com',
            'cname_chain' => [
                ['owner' => 'wrong.example.com', 'target' => 'edge.example.net'],
            ],
            'terminal' => [
                'owner' => 'edge.example.net',
                'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json, 'api.example.com');
        $this->assertTrue($result['protocolFailed']);
    }
}
