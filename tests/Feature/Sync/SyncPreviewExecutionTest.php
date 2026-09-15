<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Jobs\Connectors\SyncPreviewRunJob;
use App\Jobs\Connectors\SyncPreviewRunJobExecutionException;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\SyncPreviewConnectorCapabilityResolver;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Sync\FailingProductExecutionAggregateBuilder;
use Tests\TestCase;

class SyncPreviewExecutionTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
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
    public function preview_job_completes_with_product_level_items(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-SKU',
            'name' => 'Preview Product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PREVIEW-VAR-SKU',
            'is_active' => true,
        ]);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncPreviewConnectorCapabilityResolver::class),
        );

        $run = SyncRun::withoutWorkspaceScope()->findOrFail($run->id);
        $this->assertSame(SyncRunStatus::Completed, $run->status);

        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame(SyncPreviewOutcome::Ready, $item->previewOutcome());
    }

    #[Test]
    public function preview_job_terminalizes_failed_run_on_exception(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $builder = new FailingProductExecutionAggregateBuilder;

        try {
            (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
                $builder,
                app(SyncPreviewConnectorCapabilityResolver::class),
            );
            $this->fail('Expected preview job to throw.');
        } catch (SyncPreviewRunJobExecutionException) {
            // expected
        }

        $run = SyncRun::withoutWorkspaceScope()->findOrFail($run->id);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    #[Test]
    public function preview_job_uses_admitted_snapshot_after_live_configuration_mutation(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $admittedSnapshot = $run->configuration_snapshot;
        $this->assertSame(4, $admittedSnapshot['connector_execution_configuration']['attribute_set_id']);

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 12]),
        );

        (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncPreviewConnectorCapabilityResolver::class),
        );

        $run = SyncRun::withoutWorkspaceScope()->findOrFail($run->id);
        $this->assertSame(SyncRunStatus::Completed, $run->status);
        $this->assertSame(
            4,
            $run->configuration_snapshot['connector_execution_configuration']['attribute_set_id'],
        );
    }

    private function prepareMappedConfiguration(ConnectorAccount $account): SyncConfiguration
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

        $configuration = app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'sku',
        );

        return $configuration->refresh();
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
