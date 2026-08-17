<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\SyncPreviewConnectorCapabilityResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncPreviewRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $syncRunId,
    ) {
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function handle(
        ProductExecutionAggregateBuilder $aggregateBuilder,
        SyncPreviewConnectorCapabilityResolver $capabilityResolver,
    ): void {
        try {
            $this->execute($aggregateBuilder, $capabilityResolver);
        } catch (\Throwable) {
            $this->terminalizeFailedRun();

            throw $this->normalizeThrowable();
        }
    }

    private function execute(
        ProductExecutionAggregateBuilder $aggregateBuilder,
        SyncPreviewConnectorCapabilityResolver $capabilityResolver,
    ): void {
        $run = SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->syncRunId)
            ->first();

        if ($run === null || $run->status !== SyncRunStatus::Queued) {
            return;
        }

        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $run->sync_configuration_id)
            ->firstOrFail();

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->connectorAccountId)
            ->firstOrFail();

        $run->update([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $snapshot = $run->configuration_snapshot ?? [];
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $productIds = Product::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $aggregates = $aggregateBuilder->buildForProductIds($this->workspaceId, $productIds, $snapshot);
        $capability = $capabilityResolver->resolve($account);
        $runContext = $capability->prepareRun(
            $this->workspaceId,
            $this->connectorAccountId,
            $configuration,
            $snapshot,
        );

        DB::transaction(function () use ($run, $aggregates, $capability, $snapshot, $runContext): void {
            foreach ($aggregates as $aggregate) {
                $result = $capability->planProduct($aggregate, $snapshot, $runContext);

                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $run->workspace_id,
                    'sync_run_id' => $run->id,
                    'product_id' => $aggregate->productId,
                    'outcome' => $result->outcome,
                    'findings' => array_map(
                        static fn ($finding) => $finding->toArray(),
                        $result->findings,
                    ),
                ]);
            }

            $run->update([
                'status' => SyncRunStatus::Completed,
                'completed_at' => now(),
            ]);
        });
    }

    private function terminalizeFailedRun(): void
    {
        SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->syncRunId)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->update([
                'status' => SyncRunStatus::Failed,
                'completed_at' => now(),
            ]);
    }

    private function normalizeThrowable(): \Throwable
    {
        return new SyncPreviewRunJobExecutionException;
    }
}
