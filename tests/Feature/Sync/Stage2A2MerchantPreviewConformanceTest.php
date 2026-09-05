<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Sync\Preview\SyncPreviewFinding;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class Stage2A2MerchantPreviewConformanceTest extends TestCase
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function readiness_false_before_first_run_shows_configuration_not_ready_with_setup_notice(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        $countBefore = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'configuration_not_ready')
            ->assertSet('canStartPreview', false)
            ->assertSet('currentSetupRequired', true)
            ->assertSee('data-testid="sync-preview-configuration-not-ready"', false)
            ->assertSee(__('sync_preview.states.setup_required'))
            ->assertSee(__('sync_preview.states.setup_permission_required'))
            ->assertDontSee('data-testid="sync-preview-start"', false)
            ->call('refreshPresentation');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function readiness_false_before_first_run_with_manage_setup_shows_setup_action(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'configuration_not_ready')
            ->assertSee('data-testid="sync-preview-setup-action"', false)
            ->assertDontSee(__('sync_preview.states.setup_permission_required'));
    }

    #[Test]
    public function completed_run_with_current_readiness_false_keeps_results_and_shows_setup_notice(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload([]),
        );

        $this->createCompletedRun($workspace, $configuration->refresh(), $actor, [
            [SyncPreviewOutcome::Ready, 1],
        ]);

        $countBefore = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'completed')
            ->assertSet('canStartPreview', false)
            ->assertSet('currentSetupRequired', true)
            ->assertSee('data-testid="sync-preview-completed-summary"', false)
            ->assertSee('data-testid="sync-preview-current-setup-required"', false)
            ->assertSee(__('sync_preview.states.setup_required'))
            ->assertSee(__('sync_preview.states.setup_permission_required'))
            ->assertDontSee('data-testid="sync-preview-rerun"', false)
            ->call('refreshPresentation');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function completed_run_with_current_readiness_false_and_manage_setup_shows_setup_action(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload([]),
        );

        $this->createCompletedRun($workspace, $configuration->refresh(), $actor, [
            [SyncPreviewOutcome::Ready, 1],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'completed')
            ->assertSee('data-testid="sync-preview-completed-summary"', false)
            ->assertSee('data-testid="sync-preview-current-setup-required"', false)
            ->assertSee('data-testid="sync-preview-setup-action"', false)
            ->assertDontSee('data-testid="sync-preview-rerun"', false);
    }

    #[Test]
    public function failed_run_with_current_readiness_false_keeps_failed_state_and_shows_setup_notice(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Failed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload([]),
        );

        $countBefore = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'failed')
            ->assertSet('displayedRunId', $run->id)
            ->assertSet('canStartPreview', false)
            ->assertSet('currentSetupRequired', true)
            ->assertSee(__('sync_preview.lifecycle.failed'))
            ->assertSee('data-testid="sync-preview-current-setup-required"', false)
            ->assertSee(__('sync_preview.states.setup_required'))
            ->assertDontSee('data-testid="sync-preview-retry"', false)
            ->call('refreshPresentation');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function paused_configuration_renders_paused_state_without_start_action(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                operationalState: SyncConfigurationOperationalState::Paused,
            ),
        );

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'configuration_paused')
            ->assertSet('canStartPreview', false)
            ->assertSee('data-testid="sync-preview-configuration-paused"', false);
    }

    #[Test]
    public function export_not_enabled_renders_export_unavailable_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $configuration = app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        SyncConfiguration::withoutWorkspaceScope()
            ->whereKey($configuration->id)
            ->update(['enabled_operations' => [SyncSemanticOperation::Import->value]]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'export_unavailable')
            ->assertSee('data-testid="sync-preview-export-unavailable"', false);
    }

    #[Test]
    public function queued_state_renders_merchant_lifecycle_copy(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createRun($workspace, $configuration, $actor, SyncRunStatus::Queued);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'queued')
            ->assertSet('pollActive', true)
            ->assertSee(__('sync_preview.lifecycle.queued'));
    }

    #[Test]
    public function running_state_renders_merchant_lifecycle_copy(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createRun($workspace, $configuration, $actor, SyncRunStatus::Running);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'running')
            ->assertSet('pollActive', true)
            ->assertSee(__('sync_preview.lifecycle.running'));
    }

    #[Test]
    public function polling_refresh_does_not_create_additional_runs(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createRun($workspace, $configuration, $actor, SyncRunStatus::Queued);
        $countBefore = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('refreshPresentation')
            ->call('refreshPresentation');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function active_run_prevents_duplicate_start(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createRun($workspace, $configuration, $actor, SyncRunStatus::Queued);
        $countBefore = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('startPreview');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function late_admission_race_reprojects_active_run_without_raw_exception(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $partial = \Mockery::mock(app(SyncPreviewAdmissionService::class))->makePartial();
        $partial->shouldReceive('admit')
            ->once()
            ->andReturnUsing(function () use ($workspace, $configuration, $actor): void {
                SyncRun::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'sync_configuration_id' => $configuration->id,
                    'configuration_revision' => $configuration->configuration_revision,
                    'mode' => SyncRunMode::Preview,
                    'semantic_operation' => SyncSemanticOperation::Export,
                    'status' => SyncRunStatus::Queued,
                    'initiated_by_user_id' => $actor->id,
                    'configuration_snapshot' => ['field_mappings' => []],
                ]);

                throw SyncPreviewAdmissionException::activeRunExists($configuration->id);
            });
        app()->instance(SyncPreviewAdmissionService::class, $partial);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('startPreview')
            ->assertSet('pageState', 'queued')
            ->assertDontSee('active preview run already exists')
            ->assertDontSee('SyncPreviewAdmissionException');
    }

    #[Test]
    public function latest_run_selection_uses_created_at_then_id_tie_breaker(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $olderTime = now()->subMinutes(5);
        $newerTime = now()->subMinutes(1);
        $tieTimestamp = now()->subMinutes(10);
        $olderTieId = '00000000-0000-4000-8000-000000000001';
        $newerTieId = '00000000-0000-4000-8000-000000000002';

        $older = SyncRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => $olderTime,
        ]);
        $older->forceFill(['created_at' => $olderTime, 'updated_at' => $olderTime])->save();

        $newer = SyncRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => $newerTime,
        ]);
        $newer->forceFill(['created_at' => $newerTime, 'updated_at' => $newerTime])->save();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('displayedRunId', $newer->id);

        SyncRun::withoutWorkspaceScope()->whereKey([$older->id, $newer->id])->delete();

        $tieOlder = SyncRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => $tieTimestamp,
        ]);
        $tieOlder->forceFill([
            'id' => $olderTieId,
            'created_at' => $tieTimestamp,
            'updated_at' => $tieTimestamp,
        ])->save();

        $tieNewer = SyncRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => $tieTimestamp,
        ]);
        $tieNewer->forceFill([
            'id' => $newerTieId,
            'created_at' => $tieTimestamp,
            'updated_at' => $tieTimestamp,
        ])->save();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('displayedRunId', $newerTieId);
    }

    #[Test]
    public function field_mapping_manage_permission_shows_configure_action(): void
    {
        [$workspace, $account, $configuration, $actor] = $this->mappingScenarioActor([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

        $this->seedMappingFindingRun($workspace, $configuration, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.actions.configure_mapping'));
    }

    #[Test]
    public function field_mapping_view_permission_shows_view_only_wording(): void
    {
        [$workspace, $account, $configuration, $actor] = $this->mappingScenarioActor([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);

        $this->seedMappingFindingRun($workspace, $configuration, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.actions.view_mapping'))
            ->assertDontSee(__('sync_preview.actions.configure_mapping'));
    }

    #[Test]
    public function field_mapping_without_permission_shows_permission_required(): void
    {
        [$workspace, $account, $configuration, $actor] = $this->mappingScenarioActor([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        $this->seedMappingFindingRun($workspace, $configuration, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.status.permission_required'));
    }

    #[Test]
    public function relevant_field_mapping_change_marks_remediation_stale(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshot($account, ['color', 'size']);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::InvalidConfigurableAttribute->value,
            'subject' => 'color',
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [],
            ]],
        ]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        app(FieldMappingMutationService::class)->replace(
            $account,
            $configuration->id,
            $binding->id,
            'color',
            newExternalFieldKey: 'size',
        );

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.status.configuration_changed'));
    }

    #[Test]
    public function unrelated_option_mapping_change_does_not_stale_field_mapping_remediation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshot($account, ['color']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '49',
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::InvalidConfigurableAttribute->value,
            'subject' => 'color',
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [],
            ]],
        ]);

        FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', 'blue')
            ->update(['external_option_value' => '50']);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $fieldMappingDestination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.field_mapping'),
        );

        $this->assertNotNull($fieldMappingDestination);
        $this->assertSame(__('sync_preview.actions.configure_mapping'), $fieldMappingDestination['action_label']);
        $this->assertNull($fieldMappingDestination['status_message']);
    }

    #[Test]
    public function connector_setup_change_stales_setup_remediation_but_not_unchanged_mapping(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);
        $binding = $this->productBinding('name');

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet->value,
            'subject' => 'name',
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'name',
                'option_mappings' => [],
            ]],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ]);

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 9]),
        );

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $component->assertSee(__('sync_preview.actions.configure_mapping'));
        $component->assertSee(__('sync_preview.status.configuration_changed'));
    }

    #[Test]
    public function relevant_option_mapping_change_marks_option_remediation_stale(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshot($account, ['color']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::MissingOptionMapping->value,
            'subject' => $binding->id,
            'context' => ['internal_option_key' => 'blue'],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [],
            ]],
        ]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '49',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $optionMappingDestination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($optionMappingDestination);
        $this->assertSame(__('sync_preview.status.configuration_changed'), $optionMappingDestination['status_message']);
    }

    #[Test]
    public function unrelated_field_mapping_change_does_not_stale_connector_setup_remediation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);
        $colorBinding = $this->productVariantBinding('color');
        $nameBinding = $this->productBinding('name');

        $this->publishAuthoritativeSnapshot($account, ['name', 'color', 'size']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $nameBinding->id,
            'name',
        );
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $colorBinding->id,
            'color',
        );

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::AttributeSetUnconfigured->value,
            'subject' => null,
            'context' => [],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $nameBinding->id,
                'external_field_key' => 'name',
                'option_mappings' => [],
            ]],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ]);

        app(FieldMappingMutationService::class)->replace(
            $account,
            $configuration->id,
            $colorBinding->id,
            'color',
            newExternalFieldKey: 'size',
        );

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $connectorSetupDestination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.connector_setup'),
        );

        $this->assertNotNull($connectorSetupDestination);
        $this->assertSame(__('sync_preview.actions.configure_adobe'), $connectorSetupDestination['action_label']);
        $this->assertNull($connectorSetupDestination['status_message']);
    }

    #[Test]
    public function completed_run_with_matching_revision_still_allows_rerun_when_ready(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createCompletedRun($workspace, $configuration, $actor, [
            [SyncPreviewOutcome::Ready, 1],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('configurationChangedSinceRun', false)
            ->assertSet('canStartPreview', true)
            ->assertSee('data-testid="sync-preview-rerun"', false);
    }

    #[Test]
    public function multi_variant_product_with_findings_for_two_variants_keeps_product_identity_stable(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $binding = $this->productVariantBinding('color');

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-IGNORED',
            'name' => 'Cybex Balios S Lux',
            'brand' => 'CYBEX',
            'is_active' => true,
        ]);

        $variantA = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BALIOS-BLK',
            'is_active' => true,
        ]);

        $variantB = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BALIOS-BEI',
            'is_active' => true,
        ]);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncPreviewOutcome::Blocked,
            'findings' => [
                [
                    'code' => SyncPreviewFindingCode::MissingMappedVariantValue->value,
                    'subject' => $variantA->id,
                    'context' => ['field_binding_id' => $binding->id],
                ],
                [
                    'code' => SyncPreviewFindingCode::MissingMappedVariantValue->value,
                    'subject' => $variantB->id,
                    'context' => ['field_binding_id' => $binding->id],
                ],
            ],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $component
            ->assertSee(__('sync_preview.product_identity.multi_sku', ['count' => 2]))
            ->assertSee('BALIOS-BLK')
            ->assertSee('BALIOS-BEI')
            ->assertSee('Cybex Balios S Lux')
            ->assertDontSee('LEGACY-IGNORED');
    }

    #[Test]
    public function missing_sku_finding_never_exposes_variant_id_or_fabricates_sku(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-FALLBACK',
            'name' => 'No SKU Product',
            'brand' => 'Brand',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => '',
            'is_active' => true,
        ]);

        $binding = $this->productVariantBinding('sku');

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::MissingSku->value,
            'subject' => $variant->id,
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'sku',
                'option_mappings' => [],
            ]],
        ], $product);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.variant_context.without_sku'))
            ->assertDontSee('LEGACY-FALLBACK');
    }

    #[Test]
    public function unknown_malformed_finding_renders_safe_generic_presentation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => 'totally_unknown_code',
            'subject' => 'raw-subject',
            'context' => ['field_binding_id' => 'binding-1'],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.findings.generic'))
            ->assertDontSee('totally_unknown_code')
            ->assertDontSee('binding-1');
    }

    #[Test]
    public function livewire_snapshot_and_worklist_state_exclude_sensitive_payloads(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $binding = $this->productVariantBinding('sku');
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $this->createProductWithVariant($workspace, 'Sensitive', 'SENS', 'Brand')->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => '',
            'is_active' => true,
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::MissingSku->value,
            'subject' => $variant->id,
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'sku',
                'option_mappings' => [],
            ]],
        ], $variant->product);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $snapshotPayload = json_encode($component->snapshot);
        $effectsPayload = json_encode($component->effects);
        $worklistJson = json_encode($component->instance()->worklistRows);

        foreach ([$snapshotPayload, $effectsPayload, $worklistJson] as $payload) {
            $this->assertStringNotContainsString('missing_sku', $payload);
            $this->assertStringNotContainsString($binding->id, $payload);
            $this->assertStringNotContainsString('field_binding_id', $payload);
            $this->assertStringNotContainsString('configuration_snapshot', $payload);
            $this->assertStringNotContainsString('product_data', $payload);
            $this->assertStringNotContainsString('action_available', $payload);
            $this->assertStringNotContainsString('no_edit_surface', $payload);
            $this->assertStringNotContainsString('view_only', $payload);
            $this->assertStringNotContainsString('permission_required', $payload);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function destinationForLabel(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            foreach ($row['findings'] ?? [] as $finding) {
                foreach ($finding['destinations'] ?? [] as $destination) {
                    if (($destination['label'] ?? null) === $label) {
                        return $destination;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration, 3: User}
     */
    private function mappingScenarioActor(array $permissions): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermissions($workspace, $permissions);

        return [$workspace, $account, $configuration, $actor];
    }

    private function seedMappingFindingRun(
        Workspace $workspace,
        SyncConfiguration $configuration,
        User $actor,
    ): void {
        $account = ConnectorAccount::withoutWorkspaceScope()->findOrFail(
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->connector_account_id,
        );
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshot($account, ['color']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::InvalidConfigurableAttribute->value,
            'subject' => 'color',
            'context' => ['field_binding_id' => $binding->id],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, mixed>  $snapshotOverrides
     */
    private function createCompletedRunWithFinding(
        Workspace $workspace,
        SyncConfiguration $configuration,
        User $actor,
        array $finding,
        array $snapshotOverrides = [],
        ?Product $product = null,
    ): SyncRun {
        $product ??= $this->createProductWithVariant($workspace, 'Finding Product', 'FIND-SKU', 'Brand');

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => array_merge(['field_mappings' => []], $snapshotOverrides),
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncPreviewOutcome::Blocked,
            'findings' => [$finding],
        ]);

        return $run;
    }

    private function createRun(
        Workspace $workspace,
        SyncConfiguration $configuration,
        User $actor,
        SyncRunStatus $status,
    ): SyncRun {
        return SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => $status,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
        ]);
    }

    private function prepareReadyConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

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

    /**
     * @param  list<array{0: SyncPreviewOutcome, 1: int, 2?: Product}>  $buckets
     */
    private function createCompletedRun(
        Workspace $workspace,
        SyncConfiguration $configuration,
        User $actor,
        array $buckets,
    ): SyncRun {
        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        foreach ($buckets as $bucketIndex => $bucket) {
            [$outcome, $count, $product] = [$bucket[0], $bucket[1], $bucket[2] ?? null];

            for ($i = 0; $i < $count; $i++) {
                $itemProduct = ($product !== null && $count === 1)
                    ? $product
                    : $this->createProductWithVariant(
                        $workspace,
                        'Product '.$run->id.'-'.$bucketIndex.'-'.$i,
                        'SKU-'.$bucketIndex.'-'.$i,
                        'Brand',
                    );

                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'sync_run_id' => $run->id,
                    'product_id' => $itemProduct->id,
                    'outcome' => $outcome,
                    'findings' => $outcome === SyncPreviewOutcome::Ready
                        ? []
                        : [(new SyncPreviewFinding(SyncPreviewFindingCode::MissingName))->toArray()],
                ]);
            }
        }

        return $run;
    }

    private function createProductWithVariant(
        Workspace $workspace,
        string $name,
        string $sku,
        string $brand,
    ): Product {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-'.$sku,
            'name' => $name,
            'brand' => $brand,
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        return $product;
    }

    private function actorWithPermission(Workspace $workspace, string $permission): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($workspace, $actor, [$permission]);

        return $actor;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithPermissions(Workspace $workspace, array $permissions): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($workspace, $actor, $permissions);

        return $actor;
    }

    private function adobeAccount(?Workspace $workspace = null, array $overrides = []): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, $overrides);
    }
}
