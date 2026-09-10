<?php

namespace App\Services\Sync;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;

final class SyncRunActiveRecoveryService
{
    public function recoverStaleActiveRuns(string $syncConfigurationId): void
    {
        DB::transaction(function () use ($syncConfigurationId): void {
            $runs = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $syncConfigurationId)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                $this->recoverIfStale($run);
            }
        });
    }

    private function recoverIfStale(SyncRun $run): void
    {
        $now = now();

        if ($run->status === SyncRunStatus::Queued
            && $run->queue_dispatch_confirmed_at === null
            && $run->queued_abandon_after !== null
            && $now->greaterThanOrEqualTo($run->queued_abandon_after)
        ) {
            $run->update([
                'status' => SyncRunStatus::Failed,
                'completed_at' => $now,
            ]);

            return;
        }

        if ($run->status === SyncRunStatus::Running
            && $run->recoverable_after !== null
            && $now->greaterThanOrEqualTo($run->recoverable_after)
        ) {
            $run->update([
                'status' => SyncRunStatus::Failed,
                'completed_at' => $now,
            ]);
        }
    }
}
