<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Workspace\Rbac\Exceptions\UserDeletionForbiddenException;
use App\Support\Workspace\Rbac\Exceptions\UserLifecycleIntegrityException;
use Illuminate\Support\Facades\DB;

final class UserLifecycleService
{
    public function __construct(
        private readonly WorkspaceAccessEffectiveHolderQuery $effectiveHolderQuery,
    ) {}

    public function update(User $user, array $data): User
    {
        $wasActive = (bool) $user->is_active;
        $willDeactivate = $wasActive
            && array_key_exists('is_active', $data)
            && ! (bool) $data['is_active'];

        if ($willDeactivate) {
            return $this->deactivateWithIntegrityCheck($user, $data);
        }

        $user->update($data);

        return $user->refresh();
    }

    public function delete(User $user): void
    {
        if ($this->hasWorkspaceMemberships($user)) {
            throw new UserDeletionForbiddenException;
        }

        $user->delete();
    }

    public function hasWorkspaceMemberships(User $user): bool
    {
        return WorkspaceUser::query()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deactivateWithIntegrityCheck(User $user, array $data): User
    {
        $workspaceIds = WorkspaceUser::query()
            ->where('user_id', $user->id)
            ->pluck('workspace_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($workspaceIds === []) {
            $user->update($data);

            return $user->refresh();
        }

        return DB::transaction(function () use ($user, $data, $workspaceIds): User {
            foreach ($workspaceIds as $workspaceId) {
                Workspace::query()
                    ->whereKey($workspaceId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $user->update($data);
            $user->refresh();

            foreach ($workspaceIds as $workspaceId) {
                if (! $this->effectiveHolderQuery->hasEffectiveHolder((string) $workspaceId)) {
                    throw new UserLifecycleIntegrityException(
                        'Deactivating this user would remove the last effective manage_workspace_access holder from a workspace.',
                    );
                }
            }

            return $user;
        });
    }
}
