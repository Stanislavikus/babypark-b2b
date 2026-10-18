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
    public function production_frontend_build_is_sha_bound_and_off_host(): void
    {
        $workflow = File::get(base_path('.github/workflows/deploy.yml'));
        $deployScript = File::get(base_path('deploy.sh'));

        $this->assertStringContainsString('actions/checkout@v4', $workflow);
        $this->assertStringContainsString('actions/setup-node@v7', $workflow);
        $this->assertStringContainsString('npm ci', $workflow);
        $this->assertStringContainsString('npm run build', $workflow);
        $this->assertStringContainsString("public/build/.release-sha", $workflow);
        $this->assertStringContainsString('sha256sum', $workflow);
        $this->assertStringContainsString('scp -B', $workflow);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $workflow);
        $this->assertStringContainsString('ssh-ed25519', $workflow);
        $this->assertStringContainsString('steps.package.outputs.digest', $workflow);

        $this->assertStringContainsString('DEPLOY_BUILD_ARCHIVE', $deployScript);
        $this->assertStringContainsString('DEPLOY_BUILD_SHA256', $deployScript);
        $this->assertStringContainsString('Prebuilt archive SHA256 does not match', $deployScript);
        $this->assertStringContainsString('build/.release-sha', $deployScript);
        $this->assertStringContainsString('tar -xzf', $deployScript);
        $this->assertStringContainsString('mv "$STAGED_BUILD_DIR/build" public/build', $deployScript);
        $this->assertDoesNotMatchRegularExpression('/^\\s*npm\\s+(?:ci|run build)\\s*$/m', $deployScript);

        $this->assertLessThan(
            strpos($deployScript, 'php artisan down'),
            strpos($deployScript, 'EXPECTED_ARCHIVE='),
            'Archive validation must fail closed before maintenance begins.',
        );
        $this->assertLessThan(
            strpos($deployScript, 'php artisan migrate --force'),
            strpos($deployScript, 'mv "$STAGED_BUILD_DIR/build" public/build'),
            'Frontend assets must be installed before migrations run.',
        );
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
        $this->assertStringContainsString('php artisan db:seed --class=WorkspaceRbacPermissionSeeder --force', $content);
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
        $permissionSyncPosition = strpos($content, 'php artisan db:seed --class=WorkspaceRbacPermissionSeeder --force');
        $fieldSeedPosition = strpos($content, 'php artisan db:seed --class=CanonicalActiveFieldSeeder --force');
        $queueRestartPosition = strpos($content, 'php artisan queue:restart');
        $upPosition = strpos($content, 'php artisan up');
        $maintenanceResetPosition = strpos($content, 'MAINTENANCE_ACTIVE=0', $upPosition);
        $trapExitPosition = strpos($content, 'trap - ERR', $upPosition);
        $deploymentCompletedPosition = strpos($content, 'Deployment completed.');

        $this->assertNotFalse($maintenancePosition);
        $this->assertNotFalse($mergePosition);
        $this->assertNotFalse($migratePosition);
        $this->assertNotFalse($permissionSyncPosition);
        $this->assertNotFalse($fieldSeedPosition);
        $this->assertNotFalse($queueRestartPosition);
        $this->assertNotFalse($upPosition);
        $this->assertNotFalse($maintenanceResetPosition);
        $this->assertNotFalse($trapExitPosition);
        $this->assertNotFalse($deploymentCompletedPosition);

        $this->assertLessThan($mergePosition, $maintenancePosition, 'Maintenance mode must begin before code checkout.');
        $this->assertLessThan($queueRestartPosition, $migratePosition, 'Migrations must run before queue restart.');
        $this->assertLessThan($permissionSyncPosition, $migratePosition, 'Migrations must run before the permission catalogue sync.');
        $this->assertLessThan($upPosition, $permissionSyncPosition, 'The permission catalogue must be synchronized before leaving maintenance mode.');
        $this->assertLessThan($fieldSeedPosition, $migratePosition, 'Field materialization requires the migrated schema.');
        $this->assertLessThan($queueRestartPosition, $fieldSeedPosition, 'Canonical fields must exist before workers restart.');
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
