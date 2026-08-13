<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

trait InteractsWithWorkspaceRbac
{
    protected function defaultWorkspace(): Workspace
    {
        return Workspace::query()->where('is_default', true)->sole();
    }

    protected function makeWorkspaceMembership(Workspace $workspace, ?User $user = null, bool $active = true): WorkspaceUser
    {
        $user ??= User::factory()->create(['is_active' => true]);

        return WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => $active,
        ]);
    }

    protected function createRoleWithPermissions(
        string $workspaceId,
        string $name,
        array $permissionCodes,
        ?string $templateKey = null,
    ): WorkspaceRole {
        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'template_key' => $templateKey,
        ]);

        foreach ($permissionCodes as $permissionCode) {
            $permission = WorkspacePermission::query()
                ->where('code', $permissionCode)
                ->firstOrFail();

            DB::table('workspace_role_permissions')->insert([
                'workspace_id' => $workspaceId,
                'workspace_role_id' => $role->id,
                'workspace_permission_id' => $permission->id,
            ]);
        }

        return $role;
    }

    protected function assignRoleToMembership(WorkspaceUser $membership, WorkspaceRole $role): void
    {
        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $membership->workspace_id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);
    }

    protected function makeEffectiveHolder(
        Workspace $workspace,
        ?User $user = null,
        string $roleName = 'Access Manager',
    ): WorkspaceUser {
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            $roleName,
            [WorkspacePermissions::MANAGE_WORKSPACE_ACCESS],
        );
        $this->assignRoleToMembership($membership, $role);

        return $membership;
    }

    protected function grantManageWorkspaceAccess(Workspace $workspace, User $user): WorkspaceUser
    {
        return $this->makeEffectiveHolder($workspace, $user, 'Actor Access Role');
    }
}
