<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\SyncPreviewConnectorCapabilityResolver;
use App\Support\Sync\SyncRuntimeExecutionTiming;
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

    public int $timeout;

    private SyncRuntimeExecutionTiming $executionTiming;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $syncRunId,
        ?SyncRuntimeExecutionTiming $executionTiming = null,
    ) {
        $this->executionTiming = $executionTiming ?? SyncRuntimeExecutionTiming::snapshotFromCurrentConfig();
        $this->timeout = $this->executionTiming->jobTimeoutSeconds;
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
        $reserved = DB::transaction(function (): ?SyncRun {
            $run = SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspaceId)
                ->where('id', $this->syncRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status !== SyncRunStatus::Queued) {
                return null;
            }

            $startedAt = now();
            $leaseTimestamps = $this->executionTiming->leaseTimestampsFrom($startedAt);

            $run->update([
                'status' => SyncRunStatus::Running,
                'started_at' => $startedAt,
                'writer_deadline_at' => $leaseTimestamps['writer_deadline_at'],
                'recoverable_after' => $leaseTimestamps['recoverable_after'],
            ]);

            return $run->refresh();
        });

        if ($reserved === null) {
            return;
        }

        $run = $reserved;

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->connectorAccountId)
            ->firstOrFail();

        $snapshot = $run->configuration_snapshot ?? [];
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $productIds = Product::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $aggregates = $aggregateBuilder->buildForProductIds($this->workspaceId, $productIds, $snapshot);
        $returnedProductIds = array_map(
            static fn ($aggregate): string => $aggregate->productId,
            $aggregates,
        );

        $missingProductIds = array_values(array_diff($productIds, $returnedProductIds));

        if ($missingProductIds !== []) {
            throw new \RuntimeException(
                'Product execution aggregate builder omitted requested product ids: '.implode(', ', $missingProductIds),
            );
        }

        $capability = $capabilityResolver->resolve($account);
        $runContext = $capability->prepareRun(
            $this->workspaceId,
            $this->connectorAccountId,
            $snapshot,
        );

        foreach ($aggregates as $aggregate) {
            $result = $capability->planProduct($aggregate, $snapshot, $runContext);

            DB::transaction(function () use ($run, $aggregate, $result): void {
                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $run->workspace_id,
                    'sync_run_id' => $run->id,
                    'product_id' => $aggregate->productId,
                    'outcome' => $result->outcome->value,
                    'findings' => array_map(
                        static fn ($finding) => $finding->toArray(),
                        $result->findings,
                    ),
                ]);
            });
        }

        $completed = DB::transaction(function (): bool {
            $run = SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspaceId)
                ->where('id', $this->syncRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status !== SyncRunStatus::Running) {
                return false;
            }

            $run->update([
                'status' => SyncRunStatus::Completed,
                'completed_at' => now(),
            ]);

            return true;
        });

        if (! $completed) {
            throw new \RuntimeException('Preview run was not in running state during completion transition.');
        }
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
