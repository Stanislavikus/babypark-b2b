<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\Live\ConnectorLiveRuntimeReadiness;
use App\Support\Sync\Live\SyncLiveConnectorCapabilityResolver;
use App\Support\Sync\Live\SyncRunConsequentialWriteGate;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\SyncRuntimeExecutionTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncLiveRunJob implements Interruptible, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout;

    private SyncRuntimeExecutionTiming $executionTiming;

    private bool $interrupted = false;

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

    public function interrupted(int $signal): void
    {
        $this->interrupted = true;
    }

    public function wasInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function handle(
        ProductExecutionAggregateBuilder $aggregateBuilder,
        SyncLiveConnectorCapabilityResolver $capabilityResolver,
        ?ConnectorLiveRuntimeReadiness $liveRuntimeReadiness = null,
    ): void {
        try {
            $this->execute($aggregateBuilder, $capabilityResolver, $liveRuntimeReadiness ?? app(ConnectorLiveRuntimeReadiness::class));
        } catch (\Throwable) {
            $this->terminalizeFailedRun();

            throw $this->normalizeThrowable();
        }
    }

    private function execute(
        ProductExecutionAggregateBuilder $aggregateBuilder,
        SyncLiveConnectorCapabilityResolver $capabilityResolver,
        ConnectorLiveRuntimeReadiness $liveRuntimeReadiness,
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

        if ($run->mode !== SyncRunMode::Live || $run->semantic_operation !== SyncSemanticOperation::Export) {
            throw new \RuntimeException('Live sync run must be Products Export execution.');
        }

        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $run->sync_configuration_id)
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId((string) $run->sync_configuration_id);
        }

        if ($configuration->workspace_id !== $this->workspaceId
            || $configuration->connector_account_id !== $this->connectorAccountId
            || $configuration->data_domain !== SyncDataDomain::Products
        ) {
            throw SyncConfigurationNotFoundException::forId((string) $run->sync_configuration_id);
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->connectorAccountId)
            ->firstOrFail();

        $snapshot = $run->configuration_snapshot ?? [];
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $selection = $snapshot['selection'] ?? null;
        $selectionMode = is_array($selection) ? ($selection['mode'] ?? null) : null;

        if ($selectionMode !== 'all_products') {
            throw new \RuntimeException('Live sync snapshot selection mode is not supported.');
        }

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

        $writeGate = new SyncRunConsequentialWriteGate($this->workspaceId, $this->syncRunId);

        if ($aggregates !== []) {
            // Fresh remote readiness follows writer lease acquisition and is
            // immediately followed by the DB-fresh consequential-write gate.
            if (! $liveRuntimeReadiness->isReady($account) || ! $writeGate->permitsConsequentialWrite()) {
                $this->terminalizeFailedRun();

                return;
            }
        }

        foreach ($aggregates as $aggregate) {
            if (! $writeGate->permitsProductExecution()) {
                $this->terminalizeFailedRun();

                return;
            }

            $result = $capability->executeProduct(
                $aggregate,
                $snapshot,
                $runContext,
                $writeGate,
            );

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
            throw new \RuntimeException('Live run was not in running state during completion transition.');
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
        return new SyncLiveRunJobExecutionException;
    }
}
