<?php

namespace App\Services\Sync;

use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;

final class SyncActiveRunReadQuery
{
    public function findActive(string $workspaceId, string $syncConfigurationId): ?SyncRun
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('sync_configuration_id', $syncConfigurationId)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function isBlocked(string $syncConfigurationId): bool
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $syncConfigurationId)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->exists();
    }

    public function activePreviewBlocking(string $syncConfigurationId): bool
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $syncConfigurationId)
            ->where('mode', SyncRunMode::Preview)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->exists();
    }
}
