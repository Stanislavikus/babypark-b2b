<?php

namespace App\Console\Commands;

use App\Services\Workspace\WorkspaceAccessEffectiveHolderQuery;
use App\Services\Workspace\WorkspaceRbacLegacyBackfill;
use App\Services\Workspace\WorkspaceRbacLegacyPreflight;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceRbacLegacyPreflightException;
use App\Support\Workspace\Rbac\WorkspaceRbacLegacyTemplateDisplayNames;
use Illuminate\Console\Command;

final class WorkspaceRbacCutoverExecuteCommand extends Command
{
    protected $signature = 'workspace-rbac:cutover-execute';

    protected $description = 'EXECUTE GAP-026B workspace RBAC legacy backfill (maintenance mode only; one-time cutover)';

    public function handle(
        WorkspaceRbacLegacyPreflight $preflight,
        WorkspaceRbacLegacyBackfill $backfill,
        WorkspaceAccessEffectiveHolderQuery $holderQuery,
    ): int {
        if (! app()->isDownForMaintenance()) {
            $this->error('Refusing EXECUTE: application is not in maintenance mode.');
            $this->line('Enable maintenance mode before running workspace RBAC cutover EXECUTE.');

            return self::FAILURE;
        }

        $this->info('GAP-026B workspace RBAC cutover EXECUTE');
        $this->line('Mode: EXECUTE (legacy RBAC materialization)');
        $this->newLine();

        try {
            $preflightResult = $preflight->assertSafe();
        } catch (WorkspaceRbacLegacyPreflightException $exception) {
            $this->error('Preflight failed: unsafe for cutover.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $defaultWorkspaceId = $preflightResult->defaultWorkspaceId;

        if ($defaultWorkspaceId === null) {
            $this->error('Refusing EXECUTE: default workspace id is missing after preflight.');

            return self::FAILURE;
        }

        $this->line('Preflight: safe');
        $this->line('Default workspace id: '.$defaultWorkspaceId);
        $this->newLine();

        $displayNames = WorkspaceRbacLegacyTemplateDisplayNames::merchantSafeBootstrapDefaults();

        try {
            $backfill->execute($displayNames);
        } catch (\Throwable $exception) {
            $this->error('Backfill failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backfill completed.');

        $holderCount = $holderQuery->countEffectiveHolders($defaultWorkspaceId);

        if ($holderCount === 0) {
            $this->error('Post-backfill anti-lockout validation failed: zero effective manage_workspace_access holders.');

            return self::FAILURE;
        }

        $this->info('Post-backfill effective manage_workspace_access holders: '.$holderCount);
        $this->line('EXECUTE completed successfully.');

        return self::SUCCESS;
    }
}
