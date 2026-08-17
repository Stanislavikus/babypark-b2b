<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewPlanner;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
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
        AdobeProductExportPreviewPlanner $planner,
    ): void {
        try {
            $this->execute($aggregateBuilder, $planner);
        } catch (\Throwable) {
            $this->terminalizeFailedRun();

            throw $this->normalizeThrowable();
        }
    }

    private function execute(
        ProductExecutionAggregateBuilder $aggregateBuilder,
        AdobeProductExportPreviewPlanner $planner,
    ): void {
        $run = SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->syncRunId)
            ->first();

        if ($run === null || $run->status !== SyncRunStatus::Queued) {
            return;
        }

        $run->update([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->with(['variants'])
            ->orderBy('id')
            ->get();

        $aggregates = $aggregateBuilder->buildForProducts($products);
        $snapshot = $run->configuration_snapshot ?? [];

        DB::transaction(function () use ($run, $aggregates, $planner, $snapshot): void {
            foreach ($aggregates as $aggregate) {
                $result = $planner->plan($aggregate, is_array($snapshot) ? $snapshot : []);

                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $run->workspace_id,
                    'sync_run_id' => $run->id,
                    'product_id' => $aggregate->product->id,
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
