<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use Illuminate\Support\Facades\DB;

final class WorkspaceAccessMutationCoordinator
{
    public function __construct(
        private readonly WorkspaceAccessEffectiveHolderQuery $effectiveHolderQuery,
    ) {}

    /**
     * @param  callable(Workspace): mixed  $mutator
     */
    public function mutateLocked(Workspace $workspace, callable $mutator): mixed
    {
        return DB::transaction(function () use ($workspace, $mutator): mixed {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            $result = $mutator($lockedWorkspace);

            if (! $this->effectiveHolderQuery->hasEffectiveHolder($lockedWorkspace->id)) {
                throw new WorkspaceAccessLockoutException;
            }

            return $result;
        });
    }
}
