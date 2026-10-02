<?php

namespace App\Console\Commands;

use App\Services\Workspace\WorkspaceRbacLegacyPreflight;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightResult;
use Illuminate\Console\Command;

final class WorkspaceRbacCutoverCheckCommand extends Command
{
    protected $signature = 'workspace-rbac:cutover-check';

    protected $description = 'CHECK-ONLY diagnostics for GAP-026B workspace RBAC legacy cutover preflight (read-only; no RBAC materialization)';

    public function handle(WorkspaceRbacLegacyPreflight $preflight): int
    {
        $result = $preflight->evaluate();

        $this->renderResult($result);

        return $result->isSafe ? self::SUCCESS : self::FAILURE;
    }

    private function renderResult(WorkspaceRbacLegacyPreflightResult $result): void
    {
        $this->info('GAP-026B workspace RBAC cutover CHECK-ONLY diagnostics');
        $this->line('Mode: CHECK-ONLY (read-only; no RBAC assignment/materialization)');
        $this->line('EXECUTE: php artisan workspace-rbac:cutover-execute (maintenance mode only; GAP-026B-2)');
        $this->newLine();

        $this->line('Safe for cutover: '.($result->isSafe ? 'yes' : 'no'));

        if ($result->failureReasons !== []) {
            $this->newLine();
            $this->warn('Failure reason codes:');
            foreach ($result->failureReasonCodes() as $code) {
                $this->line('  - '.$code);
            }
        }

        $this->newLine();
        $this->info('Counts:');
        $this->line('  spatie roles: '.$result->rolesCount);
        $this->line('  spatie model_has_roles: '.$result->modelHasRolesCount);
        $this->line('  spatie model_has_permissions: '.$result->modelHasPermissionsCount);
        $this->line('  spatie role_has_permissions: '.$result->roleHasPermissionsCount);
        $this->line('  workspaces: '.$result->totalWorkspacesCount);
        $this->line('  default workspaces: '.$result->defaultWorkspacesCount);
        $this->line('  active staff Admin/Director: '.$result->activeStaffAdminDirectorCount);
        $this->line('  inactive staff Admin/Director: '.$result->inactiveStaffAdminDirectorCount);

        if ($result->defaultWorkspaceId !== null) {
            $this->line('  default workspace id: '.$result->defaultWorkspaceId);
        }

        if ($result->missingCanonicalPermissionCodes !== []) {
            $this->newLine();
            $this->warn('Missing canonical permission codes:');
            foreach ($result->missingCanonicalPermissionCodes as $code) {
                $this->line('  - '.$code);
            }
        }

        if ($result->modelHasRolesModelTypeCounts !== []) {
            $this->newLine();
            $this->line('model_has_roles by model_type:');
            foreach ($result->modelHasRolesModelTypeCounts as $modelType => $count) {
                $this->line("  {$modelType}: {$count}");
            }
        }

        if ($result->modelHasPermissionsModelTypeCounts !== []) {
            $this->newLine();
            $this->line('model_has_permissions by model_type:');
            foreach ($result->modelHasPermissionsModelTypeCounts as $modelType => $count) {
                $this->line("  {$modelType}: {$count}");
            }
        }

        $this->newLine();
        $this->line('Backfill execute() is never invoked by this command.');
    }
}
