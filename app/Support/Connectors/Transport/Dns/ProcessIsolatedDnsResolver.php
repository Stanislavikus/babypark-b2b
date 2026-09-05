<?php

namespace App\Support\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\MonotonicClock;
use App\Support\Connectors\Transport\SystemMonotonicClock;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use App\Support\Connectors\Transport\Validation\HostnameGrammar;
use Symfony\Component\Process\Process;

final class ProcessIsolatedDnsResolver implements DnsResolver
{
    private readonly string $resolverScriptPath;

    public function __construct(
        private readonly DnsChildProcessFactory $processFactory,
        private readonly DnsResponseParser $responseParser,
        private readonly MonotonicClock $clock = new SystemMonotonicClock,
        ?string $resolverScriptPath = null,
    ) {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new TransportConfigurationException(TransportConfigurationFailureReason::UnsupportedPlatform);
        }

        $this->resolverScriptPath = $resolverScriptPath
            ?? dirname(__DIR__).'/Scripts/connector-dns-resolve.php';
    }

    public function resolve(
        string $absoluteHostname,
        ConnectorTransportDeadline $deadline,
    ): DnsResolutionResult {
        if ($deadline->isExpired()) {
            return DnsResolutionResult::timeout();
        }

        $normalized = HostnameGrammar::normalize($absoluteHostname);
        $requestJson = json_encode([
            'version' => DnsProtocolConstants::VERSION,
            'hostname' => $normalized.'.',
        ], JSON_THROW_ON_ERROR);

        if (strlen($requestJson) > DnsProtocolConstants::MAX_STDIN_BYTES) {
            return DnsResolutionResult::protocolFailed();
        }

        $process = $this->processFactory->create([
            PHP_BINARY,
            $this->resolverScriptPath,
        ]);

        $stdoutBuffer = '';
        $stderrBuffer = '';
        $stdoutExceeded = false;
        $stderrExceeded = false;
        $acceptOutput = true;

        $process->setInput($requestJson);
        $process->disableOutput();

        $process->start(function (string $type, string $buffer) use (
            &$stdoutBuffer,
            &$stderrBuffer,
            &$stdoutExceeded,
            &$stderrExceeded,
            &$acceptOutput,
        ): void {
            if (! $acceptOutput) {
                return;
            }

            if ($type === Process::OUT) {
                $stdoutBuffer .= $buffer;
                if (strlen($stdoutBuffer) > DnsProtocolConstants::MAX_STDOUT_BYTES) {
                    $stdoutExceeded = true;
                    $acceptOutput = false;
                }
            } elseif ($type === Process::ERR) {
                $stderrBuffer .= $buffer;
                if (strlen($stderrBuffer) > DnsProtocolConstants::MAX_STDERR_BYTES) {
                    $stderrExceeded = true;
                    $acceptOutput = false;
                }
            }
        });

        while ($process->isRunning()) {
            if ($stdoutExceeded || $stderrExceeded) {
                $this->killProcess($process);

                return DnsResolutionResult::protocolFailed();
            }

            if ($deadline->isExpired()) {
                $acceptOutput = false;
                $process->signal(\SIGKILL);
                $cleanupResult = $this->awaitCleanup($process);

                return $cleanupResult ?? DnsResolutionResult::timeout();
            }

            $pollMs = min(
                DnsProtocolConstants::SUPERVISION_POLL_INTERVAL_MS,
                (int) floor($deadline->remainingSeconds() * 1000),
            );
            $pollMs = max(1, $pollMs);
            usleep($pollMs * 1000);
        }

        if ($stdoutExceeded || $stderrExceeded) {
            return DnsResolutionResult::protocolFailed();
        }

        if ($stderrBuffer !== '') {
            return DnsResolutionResult::protocolFailed();
        }

        if (! $process->isSuccessful()) {
            return DnsResolutionResult::protocolFailed();
        }

        $parsed = $this->responseParser->parse($stdoutBuffer, $normalized);
        if ($parsed['success'] === false) {
            if ($parsed['protocolFailed']) {
                return DnsResolutionResult::protocolFailed();
            }

            return DnsResolutionResult::dnsError($parsed['dnsError'] ?? 'lookup_failed');
        }

        return DnsResolutionResult::ok(
            $parsed['requestedHostname'],
            $parsed['cnameChain'],
            $parsed['terminalOwner'],
            $parsed['addresses'],
        );
    }

    private function killProcess(Process $process): void
    {
        if ($process->isRunning()) {
            $process->signal(\SIGKILL);
        }

        $this->awaitCleanup($process);
    }

    private function awaitCleanup(Process $process): ?DnsResolutionResult
    {
        $deadlineNs = $this->clock->nowNanoseconds()
            + (int) (DnsProtocolConstants::CLEANUP_DIAGNOSTIC_THRESHOLD_MS * 1_000_000);

        while ($process->isRunning()) {
            if ($this->clock->nowNanoseconds() >= $deadlineNs) {
                return DnsResolutionResult::cleanupFailed();
            }

            usleep(DnsProtocolConstants::SUPERVISION_POLL_INTERVAL_MS * 1000);
        }

        return null;
    }
}
