<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionDeployWorkflowContractTest extends TestCase
{
    #[Test]
    public function production_deploy_workflow_is_manual_only_and_preserves_ssh_deploy_script(): void
    {
        $workflowPath = base_path('.github/workflows/deploy.yml');
        $content = File::get($workflowPath);

        $this->assertStringContainsString('workflow_dispatch', $content);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*push\s*:\s*$/m',
            $content,
            'Production deploy must not declare an automatic push trigger.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*pull_request\s*:\s*$/m',
            $content,
            'Production deploy must not declare a pull_request trigger.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*schedule\s*:\s*$/m',
            $content,
            'Production deploy must not declare a schedule trigger.',
        );
        $this->assertStringContainsString('appleboy/ssh-action@v1.0.3', $content);
        $this->assertStringContainsString('./deploy.sh', $content);
    }

    #[Test]
    public function production_deploy_workflow_is_restricted_to_develop_and_passes_exact_sha(): void
    {
        $content = File::get(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('refs/heads/develop', $content);
        $this->assertStringContainsString('${{ github.ref }}', $content);
        $this->assertStringContainsString('${{ github.sha }}', $content);
        $this->assertStringContainsString('DEPLOY_SHA', $content);
    }

    #[Test]
    public function production_deploy_workflow_has_non_cancelling_concurrency_protection(): void
    {
        $content = File::get(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('concurrency:', $content);
        $this->assertStringContainsString('group: production-deploy', $content);
        $this->assertStringContainsString('cancel-in-progress: false', $content);
    }

    #[Test]
    public function deploy_script_requires_exact_develop_sha_and_runs_migration_aware_steps_in_order(): void
    {
        $content = File::get(base_path('deploy.sh'));

        $this->assertStringContainsString('DEPLOY_SHA', $content);
        $this->assertStringContainsString('git fetch origin develop', $content);
        $this->assertStringContainsString('origin/develop', $content);
        $this->assertStringContainsString('git merge --ff-only', $content);
        $this->assertStringContainsString('php artisan down', $content);
        $this->assertStringContainsString('php artisan migrate --force', $content);
        $this->assertStringContainsString('php artisan queue:restart', $content);
        $this->assertStringContainsString('php artisan up', $content);

        $this->assertStringContainsString('develop', $content);
        $this->assertStringNotContainsString('git pull', $content);
        $this->assertStringNotContainsString('migrate:rollback', $content);
        $this->assertStringNotContainsString('migrate:fresh', $content);
        $this->assertStringNotContainsString('migrate:refresh', $content);
        $this->assertStringNotContainsString('migrate:reset', $content);

        $maintenancePosition = strpos($content, 'php artisan down');
        $mergePosition = strpos($content, 'git merge --ff-only');
        $migratePosition = strpos($content, 'php artisan migrate --force');
        $queueRestartPosition = strpos($content, 'php artisan queue:restart');
        $upPosition = strpos($content, 'php artisan up');
        $maintenanceResetPosition = strpos($content, 'MAINTENANCE_ACTIVE=0', $upPosition);
        $trapExitPosition = strpos($content, 'trap - ERR', $upPosition);
        $deploymentCompletedPosition = strpos($content, 'Deployment completed.');

        $this->assertNotFalse($maintenancePosition);
        $this->assertNotFalse($mergePosition);
        $this->assertNotFalse($migratePosition);
        $this->assertNotFalse($queueRestartPosition);
        $this->assertNotFalse($upPosition);
        $this->assertNotFalse($maintenanceResetPosition);
        $this->assertNotFalse($trapExitPosition);
        $this->assertNotFalse($deploymentCompletedPosition);

        $this->assertLessThan($mergePosition, $maintenancePosition, 'Maintenance mode must begin before code checkout.');
        $this->assertLessThan($queueRestartPosition, $migratePosition, 'Migrations must run before queue restart.');
        $this->assertLessThan($upPosition, $queueRestartPosition, 'php artisan up must run only after queue restart on the success path.');
        $this->assertLessThan($maintenanceResetPosition, $upPosition, 'Maintenance reset must occur only after successful php artisan up.');
        $this->assertLessThan($deploymentCompletedPosition, $maintenanceResetPosition, 'Final deployment evidence must not be emitted before maintenance reset.');
        $this->assertLessThan($deploymentCompletedPosition, $trapExitPosition, 'Final deployment evidence must not be emitted before leaving the guarded failure phase.');
        $this->assertStringContainsString('ERROR: deployment did not complete.', $content);
        $this->assertStringContainsString('Maintenance state requires manual verification/recovery.', $content);
        $this->assertStringNotContainsString('Application remains in maintenance mode.', $content);
    }

    #[Test]
    public function ops1_documentation_records_migration_aware_production_deployment_contract(): void
    {
        $content = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('Stage 3E-OPS-1', $content);
        $this->assertStringContainsString('manual-only', $content);
        $this->assertStringContainsString('DEPLOY_SHA', $content);
        $this->assertStringContainsString('php artisan migrate --force', $content);
        $this->assertStringContainsString('maintenance mode', $content);
        $this->assertStringContainsString('fail-closed', $content);
        $this->assertStringContainsString('Automatic database migration rollback is forbidden', $content);
        $this->assertStringContainsString('Repository merge, SaaS production deployment, and external connector / target deployment remain separate authorization states', $content);
    }
}
