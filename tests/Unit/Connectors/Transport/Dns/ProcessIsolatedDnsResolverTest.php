<?php

namespace Tests\Unit\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Connectors\Transport\Dns\DefaultDnsChildProcessFactory;
use App\Support\Connectors\Transport\Dns\DnsChildProcessFactory;
use App\Support\Connectors\Transport\Dns\DnsResponseParser;
use App\Support\Connectors\Transport\Dns\ProcessIsolatedDnsResolver;
use App\Support\Connectors\Transport\MonotonicClock;
use App\Support\Connectors\Transport\SystemMonotonicClock;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\Support\FakeMonotonicClock;

class ProcessIsolatedDnsResolverTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = base_path('tests/Fixtures/Connectors/Transport/Dns');
    }

    #[Test]
    public function slow_child_times_out_and_is_terminated(): void
    {
        $resolver = new ProcessIsolatedDnsResolver(
            new DefaultDnsChildProcessFactory,
            new DnsResponseParser,
            new SystemMonotonicClock,
            $this->fixturesPath.'/slow-resolver.php',
        );

        $clock = new SystemMonotonicClock;
        $deadline = ConnectorTransportDeadline::fromLimits(
            new ConnectorTransportLimits(0.1, 0.2, 1024),
            $clock,
        );

        $result = $resolver->resolve('slow.example.com', $deadline);

        $this->assertTrue($result->timedOut);
    }

    #[Test]
    public function subsequent_resolution_after_timeout_still_works(): void
    {
        $clock = new FakeMonotonicClock;
        $resolver = new ProcessIsolatedDnsResolver(
            new DefaultDnsChildProcessFactory,
            new DnsResponseParser,
            $clock,
            $this->fixturesPath.'/slow-resolver.php',
        );

        $firstDeadline = new ConnectorTransportDeadline(
            $clock->nowNanoseconds() + 200_000_000,
            $clock,
        );
        $resolver->resolve('slow.example.com', $firstDeadline);

        $resolver = new ProcessIsolatedDnsResolver(
            new DefaultDnsChildProcessFactory,
            new DnsResponseParser,
            $clock,
            $this->fixturesPath.'/fake-resolver.php',
        );

        $secondDeadline = new ConnectorTransportDeadline(
            $clock->nowNanoseconds() + 5_000_000_000,
            $clock,
        );

        $result = $resolver->resolve('public.example.com', $secondDeadline);
        $this->assertTrue($result->success);
        $this->assertSame('93.184.216.34', $result->addresses[0]);
    }

    #[Test]
    public function malformed_json_is_protocol_failure(): void
    {
        $resolver = $this->resolverForFixture('malformed-json-resolver.php');
        $result = $resolver->resolve('public.example.com', $this->longDeadline());

        $this->assertTrue($result->protocolFailed);
    }

    #[Test]
    public function oversized_output_is_protocol_failure(): void
    {
        $resolver = $this->resolverForFixture('oversized-output-resolver.php');
        $result = $resolver->resolve('big.example.com', $this->longDeadline());

        $this->assertTrue($result->protocolFailed);
    }

    #[Test]
    public function nonzero_exit_is_protocol_failure(): void
    {
        $resolver = $this->resolverForFixture('nonzero-exit-resolver.php');
        $result = $resolver->resolve('public.example.com', $this->longDeadline());

        $this->assertTrue($result->protocolFailed);
    }

    #[Test]
    public function production_resolver_script_has_no_hidden_flags(): void
    {
        $contents = file_get_contents(base_path('app/Support/Connectors/Transport/Scripts/connector-dns-resolve.php'));
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('--sleep', $contents);
        $this->assertStringNotContainsString('emit-malformed-json', $contents);
        $this->assertStringNotContainsString('emit-oversized-output', $contents);
    }

    #[Test]
    public function cleanup_failure_is_reported_when_process_never_stops(): void
    {
        $clock = new AdvancingMonotonicClock(0, 300_000_000);
        $process = $this->createMock(Process::class);
        $process->method('disableOutput')->willReturnSelf();
        $process->method('setInput')->willReturnSelf();
        $process->method('start');
        $process->method('isRunning')->willReturn(true);
        $process->method('signal');

        $factory = new class($process) implements DnsChildProcessFactory
        {
            public function __construct(private readonly Process $process) {}

            public function create(array $command): Process
            {
                return $this->process;
            }
        };

        $resolver = new ProcessIsolatedDnsResolver(
            $factory,
            new DnsResponseParser,
            $clock,
            $this->fixturesPath.'/fake-resolver.php',
        );

        $deadline = new ConnectorTransportDeadline(500_000_000, $clock);
        $result = $resolver->resolve('public.example.com', $deadline);

        $this->assertTrue($result->cleanupFailed);
    }

    #[Test]
    public function rejects_oversized_stdin_request(): void
    {
        $resolver = $this->resolverForFixture('fake-resolver.php');
        $longHostname = str_repeat('a', 1200).'.com';
        $result = $resolver->resolve($longHostname, $this->longDeadline());

        $this->assertTrue($result->protocolFailed);
    }

    private function resolverForFixture(string $fixture): ProcessIsolatedDnsResolver
    {
        return new ProcessIsolatedDnsResolver(
            new DefaultDnsChildProcessFactory,
            new DnsResponseParser,
            new FakeMonotonicClock,
            $this->fixturesPath.'/'.$fixture,
        );
    }

    private function longDeadline(): ConnectorTransportDeadline
    {
        $clock = new FakeMonotonicClock;

        return new ConnectorTransportDeadline(
            $clock->nowNanoseconds() + 5_000_000_000,
            $clock,
        );
    }
}

final class AdvancingMonotonicClock implements MonotonicClock
{
    private int $now;

    public function __construct(
        int $startNanoseconds = 0,
        private readonly int $advancePerRead = 1_000_000,
    ) {
        $this->now = $startNanoseconds;
    }

    public function nowNanoseconds(): int
    {
        $this->now += $this->advancePerRead;

        return $this->now;
    }
}
