<?php

namespace Tests\Unit\Connectors\Transport;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalHttpServerProcessSafetyTest extends TestCase
{
    #[Test]
    public function unexpected_child_handler_failure_is_isolated_and_reported(): void
    {
        $output = $this->runProbe('handler-throws');

        $this->assertStringContainsString('child-failure-surfaced', $output);
    }

    #[Test]
    public function expected_early_client_close_does_not_fail_chunked_fixture(): void
    {
        $output = $this->runProbe('client-closes');

        $this->assertStringContainsString('benign-client-close', $output);
    }

    private function runProbe(string $scenario): string
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This test requires pcntl_fork.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'fork_fixture_probe_');
        $this->assertIsString($marker);

        try {
            // The subprocess owns any hazardous fork; a regression cannot fork PHPUnit.
            $process = new Process([PHP_BINARY, __DIR__.'/Support/ForkFixtureSafetyProbe.php', $scenario, $marker]);
            $process->setTimeout(60);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame("after-serve\n", file_get_contents($marker), 'The fork child escaped past the fixture handler.');

            return $process->getOutput();
        } finally {
            @unlink($marker);
        }
    }
}
