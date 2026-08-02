<?php

namespace App\Jobs\Connectors;

use App\Services\Connectors\AdobePaaSDiscoveryService;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ConnectorDiscoveryRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $discoveryRunId,
        private readonly int $retryUntilTimestamp,
    ) {
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->retryUntilTimestamp);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("connector-account:{$this->connectorAccountId}"))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(1100),
        ];
    }

    public function handle(
        AdobePaaSDiscoveryService $service,
        ConnectorDiscoveryRunPersistence $persistence,
    ): void {
        try {
            $this->handleSafely($service, $persistence);
        } catch (ConnectorDiscoveryRunJobExecutionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ConnectorDiscoveryRunJobExecutionException;
        }
    }

    private function handleSafely(
        AdobePaaSDiscoveryService $service,
        ConnectorDiscoveryRunPersistence $persistence,
    ): void {
        $slot = $persistence->reserveExecutionSlot(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->discoveryRunId,
        );

        if (! $slot['reserved']) {
            if ($slot['releaseDelaySeconds'] !== null) {
                $this->release($slot['releaseDelaySeconds']);
            }

            return;
        }

        $startedAtNs = hrtime(true);

        try {
            $result = $service->execute(
                $this->workspaceId,
                $this->connectorAccountId,
                $this->discoveryRunId,
            );
        } catch (\Throwable) {
            $elapsedNs = hrtime(true) - $startedAtNs;
            $durationMs = (int) ceil($elapsedNs / 1_000_000);

            $persistence->persistAttemptDurationOnly(
                $this->workspaceId,
                $this->connectorAccountId,
                $this->discoveryRunId,
                $durationMs,
            );

            throw new ConnectorDiscoveryRunJobExecutionException;
        }

        $elapsedNs = hrtime(true) - $startedAtNs;
        $durationMs = (int) ceil($elapsedNs / 1_000_000);

        $retryUntilAt = CarbonImmutable::createFromTimestamp($this->retryUntilTimestamp);

        $releaseDelaySeconds = $persistence->finalizeAfterVendorAttempt(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->discoveryRunId,
            $result,
            $durationMs,
            $retryUntilAt,
        );

        if ($releaseDelaySeconds !== null) {
            $this->release($releaseDelaySeconds);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(ConnectorDiscoveryRunPersistence::class)->terminalizeWithStoredVendorClassification(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->discoveryRunId,
        );
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function connectorAccountId(): string
    {
        return $this->connectorAccountId;
    }

    public function discoveryRunId(): string
    {
        return $this->discoveryRunId;
    }
}
