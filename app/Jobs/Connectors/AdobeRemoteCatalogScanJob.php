<?php

namespace App\Jobs\Connectors;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\RemoteCatalogScan;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogScanner;
use App\Support\Connectors\ConnectorAccountOperationLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AdobeRemoteCatalogScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $executionToken,
    ) {
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function uniqueId(): string
    {
        return "adobe-remote-catalog:{$this->workspaceId}:{$this->connectorAccountId}";
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(ConnectorAccountOperationLock::sharedKey($this->connectorAccountId)))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(1100),
        ];
    }

    public function handle(AdobeRemoteCatalogScanner $scanner): void
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->connectorAccountId)
            ->firstOrFail();

        $scanner->scan($account, $this->executionToken);
    }

    public function failed(?Throwable $exception): void
    {
        $scan = RemoteCatalogScan::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('connector_account_id', $this->connectorAccountId)
            ->where('data_domain', SyncDataDomain::Products->value)
            ->where('execution_token', $this->executionToken)
            ->where('status', RemoteCatalogScanStatus::Running->value)
            ->first();

        if ($scan !== null) {
            app(RemoteCatalogScanService::class)->fail(
                $scan,
                'remote_catalog_job_failed',
                $exception?->getMessage(),
            );
        }
    }
}
