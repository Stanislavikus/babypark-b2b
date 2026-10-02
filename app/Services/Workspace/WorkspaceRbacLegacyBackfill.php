<?php

namespace App\Services\Workspace;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceRbacLegacyBackfillConflictException;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyTemplateDisplayNames;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyTemplateKeys;
use Illuminate\Support\Facades\DB;

final class WorkspaceRbacLegacyBackfill
{
    public function __construct(
        private readonly WorkspaceRbacLegacyPreflight $preflight,
    ) {}

    public function execute(WorkspaceRbacLegacyTemplateDisplayNames $displayNames): void
    {
        DB::transaction(function () use ($displayNames): void {
            $preflightResult = $this->preflight->assertSafe();

            $workspaceId = $preflightResult->defaultWorkspaceId;
            if ($workspaceId === null) {
                throw new WorkspaceRbacLegacyBackfillConflictException('Default workspace is not uniquely resolvable.');
            }

            $this->validateExistingTargetState($workspaceId);

            $this->materializeTemplateRoles($workspaceId, $displayNames);

            $staffUsers = User::query()
                ->whereNull('customer_id')
                ->get();

            foreach ($staffUsers as $user) {
                $this->materializeStaffUser($workspaceId, $user);
            }
        });
    }

    private function validateExistingTargetState(string $workspaceId): void
    {
        $roles = WorkspaceRole::query()
            ->where('workspace_id', $workspaceId)
            ->get();

        foreach ($roles as $role) {
            if ($role->template_key === null || ! in_array($role->template_key, WorkspaceRbacLegacyTemplateKeys::bootstrapKeys(), true)) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    'Unknown or custom workspace role detected: '.$role->id,
                );
            }

            $expectedCodes = WorkspaceRbacLegacyTemplateKeys::permissionsForKey($role->template_key);
            $actualCodes = $this->permissionCodesForRole($workspaceId, $role->id);
            $unexpected = array_diff($actualCodes, $expectedCodes);

            if ($unexpected !== []) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    'Bootstrap role has unexpected permission assignments: '.implode(', ', $unexpected),
                );
            }
        }

        $memberships = WorkspaceUser::query()
            ->where('workspace_id', $workspaceId)
            ->get();

        foreach ($memberships as $membership) {
            $user = User::query()->find($membership->user_id);

            if ($user === null) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    "Workspace membership {$membership->id} references a missing user.",
                );
            }

            if ($user->customer_id !== null) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    "Customer-linked user {$user->id} has an existing workspace membership.",
                );
            }

            $this->assertRecognizedMembershipAssignments($workspaceId, $membership, $user);
        }

        $unknownAssignments = DB::table('workspace_user_roles')
            ->join(
                'workspace_roles',
                'workspace_roles.id',
                '=',
                'workspace_user_roles.workspace_role_id',
            )
            ->where('workspace_user_roles.workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query
                    ->whereNull('workspace_roles.template_key')
                    ->orWhereNotIn('workspace_roles.template_key', WorkspaceRbacLegacyTemplateKeys::bootstrapKeys());
            })
            ->count();

        if ($unknownAssignments > 0) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                'Unknown role assignment detected in target workspace.',
            );
        }
    }

    private function assertRecognizedMembershipAssignments(
        string $workspaceId,
        WorkspaceUser $membership,
        User $user,
    ): void {
        $assignedBootstrapRoleIds = $this->assignedBootstrapRoleIds($workspaceId, $membership->id);
        $expectedTemplateKey = $this->expectedTemplateKeyForRole($user->role);

        if ($expectedTemplateKey === null) {
            if ($assignedBootstrapRoleIds !== []) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    "Staff user {$user->id} has unexpected bootstrap role assignments.",
                );
            }

            return;
        }

        $expectedRoleId = WorkspaceRole::query()
            ->where('workspace_id', $workspaceId)
            ->where('template_key', $expectedTemplateKey)
            ->value('id');

        if ($expectedRoleId === null && $assignedBootstrapRoleIds === []) {
            return;
        }

        if ($expectedRoleId === null) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Staff user {$user->id} has bootstrap role assignments but expected template {$expectedTemplateKey} is missing.",
            );
        }

        $unexpectedAssignments = array_diff($assignedBootstrapRoleIds, [(string) $expectedRoleId]);
        if ($unexpectedAssignments !== []) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Staff user {$user->id} has unexpected bootstrap role assignments.",
            );
        }
    }

    private function materializeTemplateRoles(string $workspaceId, WorkspaceRbacLegacyTemplateDisplayNames $displayNames): void
    {
        foreach (WorkspaceRbacLegacyTemplateKeys::bootstrapKeys() as $templateKey) {
            $role = $this->resolveTemplateRole($workspaceId, $templateKey, $displayNames);
            $this->ensureRolePermissions($workspaceId, $role->id, $templateKey);
        }
    }

    private function resolveTemplateRole(
        string $workspaceId,
        string $templateKey,
        WorkspaceRbacLegacyTemplateDisplayNames $displayNames,
    ): WorkspaceRole {
        $existing = WorkspaceRole::query()
            ->where('workspace_id', $workspaceId)
            ->where('template_key', $templateKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return WorkspaceRole::query()->create([
            'workspace_id' => $workspaceId,
            'name' => $displayNames->forTemplateKey($templateKey),
            'template_key' => $templateKey,
        ]);
    }

    private function ensureRolePermissions(string $workspaceId, string $roleId, string $templateKey): void
    {
        $expectedCodes = WorkspaceRbacLegacyTemplateKeys::permissionsForKey($templateKey);
        $permissionIds = DB::table('workspace_permissions')
            ->whereIn('code', $expectedCodes)
            ->pluck('id', 'code');

        foreach ($expectedCodes as $code) {
            if (! isset($permissionIds[$code])) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    "Canonical permission missing during backfill: {$code}",
                );
            }
        }

        $actualCodes = $this->permissionCodesForRole($workspaceId, $roleId);
        $unexpected = array_diff($actualCodes, $expectedCodes);

        if ($unexpected !== []) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                'Template role has unexpected permission assignments: '.implode(', ', $unexpected),
            );
        }

        foreach ($expectedCodes as $code) {
            $permissionId = $permissionIds[$code];

            $exists = DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_role_id', $roleId)
                ->where('workspace_permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('workspace_role_permissions')->insert([
                    'workspace_id' => $workspaceId,
                    'workspace_role_id' => $roleId,
                    'workspace_permission_id' => $permissionId,
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function permissionCodesForRole(string $workspaceId, string $roleId): array
    {
        return DB::table('workspace_role_permissions')
            ->join(
                'workspace_permissions',
                'workspace_permissions.id',
                '=',
                'workspace_role_permissions.workspace_permission_id',
            )
            ->where('workspace_role_permissions.workspace_id', $workspaceId)
            ->where('workspace_role_permissions.workspace_role_id', $roleId)
            ->pluck('workspace_permissions.code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
    }

    private function materializeStaffUser(string $workspaceId, User $user): void
    {
        $membership = WorkspaceUser::query()
            ->where('user_id', $user->id)
            ->first();

        if ($membership !== null && $membership->workspace_id !== $workspaceId) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Staff user {$user->id} already has membership in a different workspace.",
            );
        }

        if ($membership === null) {
            $membership = WorkspaceUser::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'is_active' => true,
            ]);
        } elseif (! $membership->is_active) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Staff user {$user->id} membership is inactive and cannot be silently adopted.",
            );
        }

        $expectedTemplateKey = $this->expectedTemplateKeyForRole($user->role);
        $assignedBootstrapRoleIds = $this->assignedBootstrapRoleIds($workspaceId, $membership->id);

        if ($expectedTemplateKey === null) {
            if ($assignedBootstrapRoleIds !== []) {
                throw new WorkspaceRbacLegacyBackfillConflictException(
                    "Staff user {$user->id} has unexpected bootstrap role assignments.",
                );
            }

            return;
        }

        $expectedRoleId = WorkspaceRole::query()
            ->where('workspace_id', $workspaceId)
            ->where('template_key', $expectedTemplateKey)
            ->value('id');

        if ($expectedRoleId === null) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Bootstrap template role {$expectedTemplateKey} is missing.",
            );
        }

        $unexpectedAssignments = array_diff($assignedBootstrapRoleIds, [(string) $expectedRoleId]);
        if ($unexpectedAssignments !== []) {
            throw new WorkspaceRbacLegacyBackfillConflictException(
                "Staff user {$user->id} has unexpected bootstrap role assignments.",
            );
        }

        $hasExpectedAssignment = DB::table('workspace_user_roles')
            ->where('workspace_id', $workspaceId)
            ->where('workspace_user_id', $membership->id)
            ->where('workspace_role_id', $expectedRoleId)
            ->exists();

        if (! $hasExpectedAssignment) {
            DB::table('workspace_user_roles')->insert([
                'workspace_id' => $workspaceId,
                'workspace_user_id' => $membership->id,
                'workspace_role_id' => $expectedRoleId,
            ]);
        }
    }

    private function expectedTemplateKeyForRole(UserRole $role): ?string
    {
        return match ($role) {
            UserRole::Admin, UserRole::Director => WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER,
            UserRole::Merchandiser => WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR,
            UserRole::Manager, UserRole::Warehouse, UserRole::Programmer => null,
        };
    }

    /**
     * @return list<string>
     */
    private function assignedBootstrapRoleIds(string $workspaceId, string $workspaceUserId): array
    {
        return DB::table('workspace_user_roles')
            ->join(
                'workspace_roles',
                'workspace_roles.id',
                '=',
                'workspace_user_roles.workspace_role_id',
            )
            ->where('workspace_user_roles.workspace_id', $workspaceId)
            ->where('workspace_user_roles.workspace_user_id', $workspaceUserId)
            ->whereIn('workspace_roles.template_key', WorkspaceRbacLegacyTemplateKeys::bootstrapKeys())
            ->pluck('workspace_roles.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
