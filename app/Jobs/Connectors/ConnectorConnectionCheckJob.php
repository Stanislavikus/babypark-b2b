<?php

namespace App\Jobs\Connectors;

use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Services\Connectors\ConnectorConnectionCheckPersistence;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ConnectorConnectionCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $connectionCheckId,
        private readonly int $retryUntilTimestamp,
    ) {}

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
                ->expireAfter(120),
        ];
    }

    public function handle(
        AdobePaaSConnectionCheckService $service,
        ConnectorConnectionCheckPersistence $persistence,
    ): void {
        try {
            $this->handleSafely($service, $persistence);
        } catch (ConnectorConnectionCheckJobExecutionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ConnectorConnectionCheckJobExecutionException;
        }
    }

    private function handleSafely(
        AdobePaaSConnectionCheckService $service,
        ConnectorConnectionCheckPersistence $persistence,
    ): void {
        $slot = $persistence->reserveExecutionSlot(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->connectionCheckId,
        );

        if (! $slot['reserved']) {
            if ($slot['releaseDelaySeconds'] !== null) {
                $this->release($slot['releaseDelaySeconds']);
            }

            return;
        }

        $startedAtNs = hrtime(true);

        try {
            $result = $service->execute($this->workspaceId, $this->connectorAccountId);
        } catch (\Throwable) {
            $elapsedNs = hrtime(true) - $startedAtNs;
            $durationMs = (int) ceil($elapsedNs / 1_000_000);

            $persistence->persistAttemptDurationOnly(
                $this->workspaceId,
                $this->connectorAccountId,
                $this->connectionCheckId,
                $durationMs,
            );

            throw new ConnectorConnectionCheckJobExecutionException;
        }

        $elapsedNs = hrtime(true) - $startedAtNs;
        $durationMs = (int) ceil($elapsedNs / 1_000_000);

        $retryUntilAt = CarbonImmutable::createFromTimestamp($this->retryUntilTimestamp);

        $releaseDelaySeconds = $persistence->finalizeAfterVendorAttempt(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->connectionCheckId,
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
        app(ConnectorConnectionCheckPersistence::class)->terminalizeWithStoredVendorClassification(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->connectionCheckId,
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

    public function connectionCheckId(): string
    {
        return $this->connectionCheckId;
    }
}
