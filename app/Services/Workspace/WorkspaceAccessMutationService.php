<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessMutationRejectedException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessUnauthorizedException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

final class WorkspaceAccessMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly WorkspaceAccessMutationCoordinator $coordinator,
    ) {}

    public function assignRole(User $actor, Workspace $workspace, string $membershipId, string $roleId): void
    {
        $this->authorize($actor, $workspace);

        $membership = $this->resolveMembership($workspace, $membershipId);
        $role = $this->resolveRole($workspace, $roleId);

        $this->coordinator->mutateLocked($workspace, function () use ($workspace, $membership, $role): void {
            $exists = DB::table('workspace_user_roles')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_user_id', $membership->id)
                ->where('workspace_role_id', $role->id)
                ->exists();

            if ($exists) {
                return;
            }

            DB::table('workspace_user_roles')->insert([
                'workspace_id' => $workspace->id,
                'workspace_user_id' => $membership->id,
                'workspace_role_id' => $role->id,
            ]);
        });
    }

    public function removeRole(User $actor, Workspace $workspace, string $membershipId, string $roleId): void
    {
        $this->authorize($actor, $workspace);

        $membership = $this->resolveMembership($workspace, $membershipId);
        $role = $this->resolveRole($workspace, $roleId);

        $this->coordinator->mutateLocked($workspace, function () use ($workspace, $membership, $role): void {
            DB::table('workspace_user_roles')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_user_id', $membership->id)
                ->where('workspace_role_id', $role->id)
                ->delete();
        });
    }

    public function activateMembership(User $actor, Workspace $workspace, string $membershipId): void
    {
        $this->authorize($actor, $workspace);

        $membership = $this->resolveMembership($workspace, $membershipId);

        $this->coordinator->mutateLocked($workspace, function () use ($membership): void {
            if ($membership->is_active) {
                return;
            }

            $membership->update(['is_active' => true]);
        });
    }

    public function deactivateMembership(User $actor, Workspace $workspace, string $membershipId): void
    {
        $this->authorize($actor, $workspace);

        $membership = $this->resolveMembership($workspace, $membershipId);

        $this->coordinator->mutateLocked($workspace, function () use ($membership): void {
            if (! $membership->is_active) {
                return;
            }

            $membership->update(['is_active' => false]);
        });
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    public function createRole(User $actor, Workspace $workspace, string $name, array $permissionCodes): WorkspaceRole
    {
        $this->authorize($actor, $workspace);

        $permissionIds = $this->resolveCanonicalPermissionIds($permissionCodes);

        return $this->coordinator->mutateLocked($workspace, function () use ($workspace, $name, $permissionIds): WorkspaceRole {
            $role = WorkspaceRole::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'template_key' => null,
            ]);

            $this->syncRolePermissions($workspace->id, $role->id, $permissionIds);

            return $role->refresh();
        });
    }

    public function renameRole(User $actor, Workspace $workspace, string $roleId, string $name): WorkspaceRole
    {
        $this->authorize($actor, $workspace);

        $role = $this->resolveRole($workspace, $roleId);

        return $this->coordinator->mutateLocked($workspace, function () use ($role, $name): WorkspaceRole {
            $role->update(['name' => $name]);

            return $role->refresh();
        });
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    public function updateRolePermissions(User $actor, Workspace $workspace, string $roleId, array $permissionCodes): WorkspaceRole
    {
        $this->authorize($actor, $workspace);

        $role = $this->resolveRole($workspace, $roleId);
        $permissionIds = $this->resolveCanonicalPermissionIds($permissionCodes);

        return $this->coordinator->mutateLocked($workspace, function () use ($workspace, $role, $permissionIds): WorkspaceRole {
            $this->syncRolePermissions($workspace->id, $role->id, $permissionIds);

            return $role->refresh();
        });
    }

    public function deleteRole(User $actor, Workspace $workspace, string $roleId): void
    {
        $this->authorize($actor, $workspace);

        $role = $this->resolveRole($workspace, $roleId);

        $assigned = DB::table('workspace_user_roles')
            ->where('workspace_id', $workspace->id)
            ->where('workspace_role_id', $role->id)
            ->exists();

        if ($assigned) {
            throw WorkspaceAccessMutationRejectedException::roleStillAssigned($role->id);
        }

        $this->coordinator->mutateLocked($workspace, function () use ($workspace, $role): void {
            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_role_id', $role->id)
                ->delete();

            $role->delete();
        });
    }

    private function authorize(User $actor, Workspace $workspace): void
    {
        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)) {
            throw new WorkspaceAccessUnauthorizedException;
        }
    }

    private function resolveMembership(Workspace $workspace, string $membershipId): WorkspaceUser
    {
        $membership = WorkspaceUser::query()->find($membershipId);

        if ($membership === null || $membership->workspace_id !== $workspace->id) {
            throw WorkspaceAccessMutationRejectedException::foreignMembership($membershipId, $workspace->id);
        }

        return $membership;
    }

    private function resolveRole(Workspace $workspace, string $roleId): WorkspaceRole
    {
        $role = WorkspaceRole::query()->find($roleId);

        if ($role === null || $role->workspace_id !== $workspace->id) {
            throw WorkspaceAccessMutationRejectedException::foreignRole($roleId, $workspace->id);
        }

        return $role;
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return list<string>
     */
    private function resolveCanonicalPermissionIds(array $permissionCodes): array
    {
        $normalized = array_values(array_unique($permissionCodes));
        $unknown = array_values(array_diff($normalized, WorkspacePermissions::catalogue()));

        if ($unknown !== []) {
            throw WorkspaceAccessMutationRejectedException::unknownPermissionCodes($unknown);
        }

        return WorkspacePermission::query()
            ->whereIn('code', $normalized)
            ->orderBy('code')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<string>  $permissionIds
     */
    private function syncRolePermissions(string $workspaceId, string $roleId, array $permissionIds): void
    {
        DB::table('workspace_role_permissions')
            ->where('workspace_id', $workspaceId)
            ->where('workspace_role_id', $roleId)
            ->delete();

        foreach ($permissionIds as $permissionId) {
            DB::table('workspace_role_permissions')->insert([
                'workspace_id' => $workspaceId,
                'workspace_role_id' => $roleId,
                'workspace_permission_id' => $permissionId,
            ]);
        }
    }
}
