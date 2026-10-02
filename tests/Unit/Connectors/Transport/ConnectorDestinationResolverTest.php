<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\ConnectorDestinationKind;
use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\Dns\DnsResolutionResult;
use App\Support\Connectors\Transport\Internal\ConnectorDestinationResolverImpl;
use App\Support\Connectors\Transport\TransportFailureReason;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\Support\FakeMonotonicClock;

class ConnectorDestinationResolverTest extends TestCase
{
    use AssertsTransportSecretsSafely;

    #[Test]
    public function cname_to_public_address_is_accepted(): void
    {
        $resolver = new ConnectorDestinationResolverImpl(new FakeDnsResolver([
            'cname-public.example.com' => DnsResolutionResult::ok(
                'cname-public.example.com',
                [['owner' => 'cname-public.example.com', 'target' => 'edge.example.net']],
                'edge.example.net',
                ['93.184.216.34'],
            ),
        ]));

        $destination = $resolver->resolveAndValidate(
            new Uri('https://cname-public.example.com/path'),
            $this->deadline(),
        );

        $this->assertSame(ConnectorDestinationKind::DnsHostname, $destination->kind);
        $this->assertSame('93.184.216.34', $destination->pinnedIp);
    }

    #[Test]
    public function cname_to_unsafe_address_is_rejected(): void
    {
        $resolver = new ConnectorDestinationResolverImpl(new FakeDnsResolver([
            'cname-unsafe.example.com' => DnsResolutionResult::ok(
                'cname-unsafe.example.com',
                [['owner' => 'cname-unsafe.example.com', 'target' => 'internal.example.net']],
                'internal.example.net',
                ['10.0.0.1'],
            ),
        ]));

        $this->expectException(ConnectorTransportException::class);
        $this->expectExceptionCode(0);
        try {
            $resolver->resolveAndValidate(new Uri('https://cname-unsafe.example.com'), $this->deadline());
        } catch (ConnectorTransportException $exception) {
            $this->assertSame(TransportFailureReason::UnsafeDestination, $exception->reason);
            throw $exception;
        }
    }

    #[Test]
    public function mixed_safe_and_unsafe_terminal_set_is_rejected(): void
    {
        $resolver = new ConnectorDestinationResolverImpl(new FakeDnsResolver([
            'mixed.example.com' => DnsResolutionResult::ok('mixed.example.com', [], 'mixed.example.com', [
                '93.184.216.34',
                '10.0.0.1',
            ]),
        ]));

        try {
            $resolver->resolveAndValidate(new Uri('https://mixed.example.com'), $this->deadline());
            $this->fail('Expected unsafe rejection.');
        } catch (ConnectorTransportException $exception) {
            $this->assertSame(TransportFailureReason::UnsafeDestination, $exception->reason);
        }
    }

    #[Test]
    public function resolver_canary_does_not_leak_sensitive_uri_query(): void
    {
        $resolver = new ConnectorDestinationResolverImpl(new FakeDnsResolver([]));

        try {
            $resolver->resolveAndValidate(
                new Uri('https://127.0.0.1/path?'.$this->canaryQuery()),
                $this->deadline(),
            );
            $this->fail('Expected unsafe rejection.');
        } catch (ConnectorTransportException $exception) {
            $this->assertExceptionDoesNotLeakCanary($exception);
        }
    }

    private function deadline(): ConnectorTransportDeadline
    {
        $clock = new FakeMonotonicClock;

        return new ConnectorTransportDeadline($clock->nowNanoseconds() + 5_000_000_000, $clock);
    }
}
