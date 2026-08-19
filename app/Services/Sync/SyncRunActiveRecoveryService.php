<?php

namespace App\Services\Sync;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SyncRunActiveRecoveryService
{
    public function recoverStaleActiveRuns(string $syncConfigurationId, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        DB::transaction(function () use ($syncConfigurationId, $now): void {
            $runs = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $syncConfigurationId)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                if ($this->shouldRecoverQueued($run, $now) || $this->shouldRecoverRunning($run, $now)) {
                    $run->update([
                        'status' => SyncRunStatus::Failed,
                        'completed_at' => $now,
                    ]);
                }
            }
        });
    }

    private function shouldRecoverQueued(SyncRun $run, CarbonInterface $now): bool
    {
        return $run->status === SyncRunStatus::Queued
            && $run->queue_dispatch_confirmed_at === null
            && $run->queued_abandon_after !== null
            && $now->greaterThanOrEqualTo($run->queued_abandon_after);
    }

    private function shouldRecoverRunning(SyncRun $run, CarbonInterface $now): bool
    {
        return $run->status === SyncRunStatus::Running
            && $run->recoverable_after !== null
            && $now->greaterThanOrEqualTo($run->recoverable_after);
    }
}
