<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Jobs\Connectors\SyncPreviewRunJob;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class SyncPreviewAdmissionTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);
    }

    #[Test]
    public function admission_requires_run_sync_preview_permission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->makeWorkspaceMembership($account->workspace, $actor);

        $this->expectException(SyncPreviewAdmissionException::class);

        app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    #[Test]
    public function admission_creates_queued_run_and_dispatches_job_after_commit(): void
    {
        Bus::fake();

        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->assertSame(SyncRunStatus::Queued, $run->status);
        $this->assertSame(SyncRunMode::Preview, $run->mode);
        $this->assertSame('platform.sync-run-input.v1', $run->configuration_snapshot['version']);
        $this->assertSame('all_products', $run->configuration_snapshot['selection']['mode']);

        Bus::assertDispatched(SyncPreviewRunJob::class);
    }

    #[Test]
    public function admission_rejects_duplicate_active_run(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->expectException(SyncPreviewAdmissionException::class);

        app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    private function preparePreviewReadyConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = $this->createProductsSyncConfiguration($account);

        app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                enabledOperations: [SyncSemanticOperation::Export],
                operationalState: SyncConfigurationOperationalState::Enabled,
            ),
        );

        return app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );
    }

    private function grantPreviewPermission(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Preview Runner',
            [WorkspacePermissions::RUN_SYNC_PREVIEW],
        );
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }
}
