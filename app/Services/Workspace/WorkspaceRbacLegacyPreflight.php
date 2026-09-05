<?php

namespace App\Services\Workspace;

use App\Enums\UserRole;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceRbacLegacyPreflightException;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightFailureReason;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightResult;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

final class WorkspaceRbacLegacyPreflight
{
    public function evaluate(): WorkspaceRbacLegacyPreflightResult
    {
        $failureReasons = [];

        $rolesCount = (int) DB::table('roles')->count();
        if ($rolesCount > 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::SpatieRolesNonEmpty;
        }

        $modelHasRolesModelTypeCounts = $this->groupedModelTypeCounts('model_has_roles');
        $modelHasRolesCount = array_sum($modelHasRolesModelTypeCounts);
        if ($modelHasRolesCount > 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::SpatieModelHasRolesNonEmpty;
        }

        $modelHasPermissionsModelTypeCounts = $this->groupedModelTypeCounts('model_has_permissions');
        $modelHasPermissionsCount = array_sum($modelHasPermissionsModelTypeCounts);
        if ($modelHasPermissionsCount > 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::SpatieModelHasPermissionsNonEmpty;
        }

        $roleHasPermissionsCount = (int) DB::table('role_has_permissions')->count();
        if ($roleHasPermissionsCount > 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::SpatieRoleHasPermissionsNonEmpty;
        }

        $totalWorkspaces = (int) DB::table('workspaces')->count();
        $defaultWorkspaces = (int) DB::table('workspaces')->where('is_default', true)->count();

        if ($totalWorkspaces === 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::ZeroWorkspaces;
        } elseif ($totalWorkspaces > 1) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::MultipleWorkspaces;
        }

        if ($defaultWorkspaces === 0) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::ZeroDefaultWorkspaces;
        } elseif ($defaultWorkspaces > 1) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::MultipleDefaultWorkspaces;
        }

        $defaultWorkspaceId = null;
        if ($totalWorkspaces === 1 && $defaultWorkspaces === 1) {
            $defaultWorkspaceId = DB::table('workspaces')
                ->where('is_default', true)
                ->value('id');
        }

        $activeStaffAdminDirectorCount = (int) DB::table('users')
            ->whereNull('customer_id')
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Director->value])
            ->count();

        $inactiveStaffAdminDirectorCount = (int) DB::table('users')
            ->whereNull('customer_id')
            ->where('is_active', false)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Director->value])
            ->count();

        if ($activeStaffAdminDirectorCount === 0) {
            if ($inactiveStaffAdminDirectorCount > 0) {
                $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::OnlyInactiveStaffAdminOrDirector;
            } else {
                $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::NoActiveStaffAdminOrDirector;
            }
        }

        $existingCodes = DB::table('workspace_permissions')->pluck('code')->all();
        $missingCanonicalPermissionCodes = array_values(array_diff(
            WorkspacePermissions::catalogue(),
            $existingCodes,
        ));

        if ($missingCanonicalPermissionCodes !== []) {
            $failureReasons[] = WorkspaceRbacLegacyPreflightFailureReason::MissingCanonicalPermissionCodes;
        }

        return new WorkspaceRbacLegacyPreflightResult(
            isSafe: $failureReasons === [],
            failureReasons: $failureReasons,
            rolesCount: $rolesCount,
            modelHasRolesCount: $modelHasRolesCount,
            modelHasRolesModelTypeCounts: $modelHasRolesModelTypeCounts,
            modelHasPermissionsCount: $modelHasPermissionsCount,
            modelHasPermissionsModelTypeCounts: $modelHasPermissionsModelTypeCounts,
            roleHasPermissionsCount: $roleHasPermissionsCount,
            totalWorkspacesCount: $totalWorkspaces,
            defaultWorkspacesCount: $defaultWorkspaces,
            activeStaffAdminDirectorCount: $activeStaffAdminDirectorCount,
            inactiveStaffAdminDirectorCount: $inactiveStaffAdminDirectorCount,
            defaultWorkspaceId: $defaultWorkspaceId !== null ? (string) $defaultWorkspaceId : null,
            missingCanonicalPermissionCodes: $missingCanonicalPermissionCodes,
        );
    }

    public function assertSafe(): WorkspaceRbacLegacyPreflightResult
    {
        $result = $this->evaluate();

        if (! $result->isSafe) {
            throw WorkspaceRbacLegacyPreflightException::fromResult($result);
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function groupedModelTypeCounts(string $table): array
    {
        $rows = DB::table($table)
            ->select('model_type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('model_type')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->model_type] = (int) $row->aggregate;
        }

        return $counts;
    }
}
