<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
use App\Filament\Pages\Sync\ManageSyncFieldOptionMappings;
use App\Models\ConnectorAccount;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldDefinitionInternalOptionValidator;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingAuthorizationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
use App\Support\Sync\FieldOptionMappingMutationContext;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\TestSyncSupportConnectorAccountSchema;
use Tests\Support\Connectors\TestSyncSupportConnectorAdapter;
use Tests\Support\Sync\CountingTestFieldOptionMappingOptionValidator;
use Tests\Support\Sync\TestFieldOptionMappingOptionValidator;
use Tests\Support\Sync\TestSyncPreviewCapability;
use Tests\Support\Sync\TransactionAwareTestFieldOptionMappingOptionValidator;
use Tests\TestCase;

class Stage2BOptionMappingTest extends TestCase
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
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function manage_sync_mappings_allows_option_mapping_mutations(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('confirmMapping', 'blue', '93')
            ->assertNotified(__('sync_option_mappings.notifications.confirmed'));

        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);
    }

    #[Test]
    public function view_sync_mappings_allows_read_only_without_mutation_controls(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertOk()
            ->assertSet('canMutate', false)
            ->assertDontSeeHtml('data-testid="sync-option-mapping-confirm"')
            ->assertDontSeeHtml('data-testid="sync-option-mapping-remove"');
    }

    #[Test]
    public function run_sync_preview_only_actor_cannot_access_option_mapping_page(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::RUN_SYNC_PREVIEW]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertForbidden();
    }

    #[Test]
    public function manage_sync_configurations_only_actor_cannot_access_option_mapping_page(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertForbidden();
    }

    #[Test]
    public function connector_read_only_actor_cannot_access_option_mapping_page(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantConnectorView($account->workspace, $actor);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertForbidden();
    }

    #[Test]
    public function permission_revocation_after_mount_fails_closed_on_refresh(): void
    {
        $workspace = $this->defaultWorkspace();
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertOk();

        DB::table('workspace_user_roles')->where('workspace_user_id', $membership->id)->delete();

        $component->call('$refresh')->assertForbidden();
    }

    #[Test]
    public function foreign_workspace_account_fails_closed(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = ConnectorAccount::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Foreign Account',
            'auth_profile' => $account->auth_profile,
            'base_url' => 'https://foreign.example.com',
            'store_code' => 'default',
            'is_enabled' => true,
            'settings' => [],
            'credentials' => ['token' => 'secret'],
        ]);
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, [
                'account' => $foreignAccount->id,
                'configuration' => $configuration->id,
                'mapping' => $mapping->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function foreign_sync_configuration_for_same_workspace_account_fails_closed(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $foreignConfiguration = $this->createProductsSyncConfiguration(
            $this->createSyncSupportAccount(),
        );
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, [
                'account' => $account->id,
                'configuration' => $foreignConfiguration->id,
                'mapping' => $mapping->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function random_field_mapping_uuid_fails_closed(): void
    {
        [$account, $configuration] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
                'mapping' => (string) Str::uuid(),
            ])
            ->assertNotFound();
    }

    #[Test]
    public function removed_field_mapping_returns_not_found(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        FieldMapping::withoutWorkspaceScope()->whereKey($mapping->id)->delete();

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertNotFound();
    }

    #[Test]
    public function read_model_lists_internal_options_from_field_definition(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertOk();

        $internalKeys = collect($component->instance()->displayRows)
            ->pluck('internal_option_key')
            ->all();

        $this->assertEqualsCanonicalizing(['blue', 'pink'], $internalKeys);
    }

    #[Test]
    public function read_model_shows_localized_internal_option_labels(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertSee('Синій')
            ->assertSee('Рожевий');
    }

    #[Test]
    public function non_select_field_mapping_is_not_eligible_for_option_page(): void
    {
        [$account, $configuration] = $this->colorMappingFixture();
        $skuBinding = $this->productVariantBinding('sku');
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $this->publishAuthoritativeSnapshot($account, ['sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $skuBinding->id,
            'sku',
        );

        $skuMapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $skuBinding->id)
            ->sole();

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $skuMapping))
            ->assertNotFound();
    }

    #[Test]
    public function multiselect_field_definition_is_not_eligible_for_option_page(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'multi_color_test',
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Мульти'],
            'description' => null,
            'validation_rules' => [
                'options' => [
                    ['code' => 'red', 'labels' => ['uk' => 'Червоний']],
                ],
            ],
            'is_localizable' => false,
            'is_multi_value' => true,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 999,
            'status' => AttributeStatus::Active,
        ]);

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'multi_color' => [['value' => '10', 'label' => 'Red']],
        ]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'multi_color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertNotFound();
    }

    #[Test]
    public function authoritative_external_option_search_resolves_from_snapshot(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $choices = app(FieldOptionMappingAuthorizationService::class)->searchExternalOptionChoices(
            $actor,
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $mapping->id,
        );

        $this->assertSame(['93' => 'Blue', '94' => 'Pink'], $choices);
    }

    #[Test]
    public function livewire_snapshot_excludes_complete_external_option_catalog(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping));

        $snapshotPayload = json_encode($component->snapshot);
        $effectsPayload = json_encode($component->effects);

        $this->assertStringNotContainsString('externalOptionChoices', $snapshotPayload);
        $this->assertStringNotContainsString('"94"', $snapshotPayload);
        $this->assertStringNotContainsString('Pink', $snapshotPayload);
        $this->assertStringNotContainsString('"94"', $effectsPayload);
        $this->assertStringNotContainsString('Pink', $effectsPayload);
    }

    #[Test]
    public function external_option_choice_search_is_scoped_and_performs_zero_http(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        Http::fake();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $choices = app(FieldOptionMappingAuthorizationService::class)->searchExternalOptionChoices(
            $actor,
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $mapping->id,
            'Pin',
        );

        $this->assertSame(['94' => 'Pink'], $choices);
        Http::assertNothingSent();
    }

    #[Test]
    public function orphan_exact_confirm_is_rejected_without_external_validation(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $validator = $this->bindCountingValidator();
        $service = app(FieldOptionMappingMutationService::class);
        $revisionBefore = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        try {
            $service->confirm($account, $configuration->id, $mapping->id, 'green', '95');
            $this->fail('Expected orphan exact confirm to be rejected.');
        } catch (FieldMappingValidationException) {
        }

        $this->assertSame(0, $validator->validateCallCount);
        $this->assertSame(
            $revisionBefore,
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision,
        );
        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'green',
            'external_option_value' => '95',
        ]);
    }

    #[Test]
    public function orphan_exact_replace_is_rejected_without_external_validation(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $validator = $this->bindCountingValidator();
        $service = app(FieldOptionMappingMutationService::class);
        $revisionBefore = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        try {
            $service->replace(
                $account,
                $configuration->id,
                $mapping->id,
                'green',
                '95',
                newExternalOptionValue: '95',
            );
            $this->fail('Expected orphan exact replace to be rejected.');
        } catch (FieldMappingValidationException) {
        }

        $this->assertSame(0, $validator->validateCallCount);
        $this->assertSame(
            $revisionBefore,
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision,
        );
    }

    #[Test]
    public function valid_current_exact_confirm_is_idempotent_without_external_validation(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $validator = $this->bindCountingValidator();
        $service = app(FieldOptionMappingMutationService::class);

        $service->confirm($account, $configuration->id, $mapping->id, 'blue', '93');
        $revisionAfterFirst = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertSame(1, $validator->validateCallCount);

        $service->confirm($account, $configuration->id, $mapping->id, 'blue', '93');
        $revisionAfterSecond = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $this->assertSame(1, $validator->validateCallCount);
        $this->assertSame($revisionAfterFirst, $revisionAfterSecond);
    }

    #[Test]
    public function failure_notification_never_serializes_raw_exception_text(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $expectedBody = __('sync_option_mappings.errors.invalid_action');

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('confirmMapping', 'purple', '93')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('sync_option_mappings.notifications.failed'))
                    ->body($expectedBody),
            );

        $effectsPayload = json_encode($component->effects);

        $this->assertStringNotContainsString('purple', $effectsPayload);
        $this->assertStringNotContainsString('field definition', strtolower($effectsPayload));
        $this->assertStringNotContainsString('Internal option is not valid', $effectsPayload);
    }

    #[Test]
    public function external_value_unavailable_row_shows_remove_for_manage_actor(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'pink',
            'external_option_value' => '999',
        ]);
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertSeeHtml('data-testid="sync-option-mapping-remove"');
    }

    #[Test]
    public function external_value_unavailable_remove_succeeds_without_external_validation(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'pink',
            'external_option_value' => '999',
        ]);
        Http::fake();
        $validator = $this->bindCountingValidator();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('removeMapping', 'pink', '999')
            ->assertNotified(__('sync_option_mappings.notifications.removed'));

        $this->assertSame(0, $validator->validateCallCount);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'pink',
        ]);
    }

    #[Test]
    public function external_value_unavailable_row_hides_remove_for_view_only_actor(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'pink',
            'external_option_value' => '999',
        ]);
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertDontSeeHtml('data-testid="sync-option-mapping-remove"');
    }

    #[Test]
    public function missing_external_choices_do_not_block_removal_of_existing_correspondence(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');

        FieldMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'color',
        ]);

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        Http::fake();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('removeMapping', 'blue', '93')
            ->assertNotified(__('sync_option_mappings.notifications.removed'));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
        ]);
    }

    #[Test]
    public function read_model_shows_mapped_unmapped_and_external_value_unavailable_states(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);
        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'pink',
            'external_option_value' => '999',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping));

        $rowsByKey = collect($component->instance()->displayRows)->keyBy('internal_option_key');

        $this->assertSame('mapped', $rowsByKey['blue']['semantic_state']);
        $this->assertSame('external_value_unavailable', $rowsByKey['pink']['semantic_state']);
        $this->assertCount(2, $rowsByKey);
    }

    #[Test]
    public function missing_snapshot_keeps_read_model_safe_without_external_choices(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');

        FieldMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'color',
        ]);

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertSet('externalChoicesResolvable', false)
            ->assertSee(__('sync_option_mappings.no_external_choices_notice', ['platform' => $account->connectorDefinition->name]))
            ->assertSee('unmapped', false);
    }

    #[Test]
    public function search_filters_by_internal_and_external_labels(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->set('search', 'Рожев');

        $this->assertCount(1, $component->instance()->displayRows);
        $this->assertSame('pink', $component->instance()->displayRows[0]['internal_option_key']);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $component
            ->call('$refresh')
            ->set('search', 'Blue');

        $this->assertCount(1, $component->instance()->displayRows);
        $this->assertSame('blue', $component->instance()->displayRows[0]['internal_option_key']);
    }

    #[Test]
    public function read_model_projects_without_http_calls(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        Http::fake();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('$refresh')
            ->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function view_permission_hides_mutation_controls_on_option_mapping_page(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertDontSee(__('sync_option_mappings.actions.confirm'))
            ->assertDontSee(__('sync_option_mappings.actions.remove'));
    }

    #[Test]
    public function livewire_snapshot_excludes_orphan_internal_option_keys(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping));

        $snapshotPayload = json_encode($component->snapshot);
        $effectsPayload = json_encode($component->effects);

        $this->assertStringNotContainsString('green', $snapshotPayload);
        $this->assertStringNotContainsString('green', $effectsPayload);
        $this->assertCount(1, $component->instance()->staleRows);
    }

    #[Test]
    public function orphan_internal_option_appears_in_stale_section_not_worklist(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping));

        $internalKeys = collect($component->instance()->displayRows)->pluck('internal_option_key')->all();

        $this->assertEqualsCanonicalizing(['blue', 'pink'], $internalKeys);
        $this->assertCount(1, $component->instance()->staleRows);
    }

    #[Test]
    public function stale_correspondence_section_visible_to_view_only_actor(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->assertSee(__('sync_option_mappings.stale.section_title'))
            ->assertDontSeeHtml('data-testid="sync-option-mapping-stale-remove"');
    }

    #[Test]
    public function manage_actor_can_remove_stale_correspondence_by_id(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $staleMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', 'green')
            ->sole();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('removeStaleCorrespondence', $staleMapping->id)
            ->assertNotified(__('sync_option_mappings.notifications.removed'));

        $this->assertDatabaseMissing('field_option_mappings', ['id' => $staleMapping->id]);
    }

    #[Test]
    public function stale_correspondence_removal_does_not_issue_http_calls(): void
    {
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $staleMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', 'green')
            ->sole();
        Http::fake();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('removeStaleCorrespondence', $staleMapping->id);

        Http::assertNothingSent();
    }

    #[Test]
    public function stale_correspondence_removal_does_not_change_variant_field_values(): void
    {
        $workspace = $this->defaultWorkspace();
        [$account, $configuration, $mapping] = $this->orphanGreenFixture();
        $colorBinding = $this->productVariantBinding('color');
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => Product::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'LEGACY',
                'name' => 'Color Product',
                'is_active' => true,
            ])->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'COLOR-VAR',
            'is_active' => true,
        ]);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $colorBinding->id,
            'value_text' => 'blue',
        ]);

        $staleMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', 'green')
            ->sole();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldOptionMappings::class, $this->optionPageParameters($account, $configuration, $mapping))
            ->call('removeStaleCorrespondence', $staleMapping->id);

        $this->assertDatabaseHas('variant_field_values', [
            'variant_id' => $variant->id,
            'field_binding_id' => $colorBinding->id,
            'value_text' => 'blue',
        ]);
    }

    #[Test]
    public function confirm_replace_and_remove_mutate_through_coordinator_and_update_revision(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $service = app(FieldOptionMappingMutationService::class);
        $revisionBefore = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $service->confirm($account, $configuration->id, $mapping->id, 'blue', '93');
        $revisionAfterConfirm = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertNotSame($revisionBefore, $revisionAfterConfirm);

        $service->replace($account, $configuration->id, $mapping->id, 'blue', '93', newExternalOptionValue: '94');
        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '94',
        ]);

        $service->remove($account, $configuration->id, $mapping->id, 'blue', '94');
        $this->assertDatabaseMissing('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
        ]);
    }

    #[Test]
    public function idempotent_confirm_does_not_bump_revision(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $service = app(FieldOptionMappingMutationService::class);

        $service->confirm($account, $configuration->id, $mapping->id, 'blue', '93');
        $revisionAfterFirst = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $service->confirm($account, $configuration->id, $mapping->id, 'blue', '93');
        $revisionAfterSecond = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $this->assertSame($revisionAfterFirst, $revisionAfterSecond);
    }

    #[Test]
    public function unknown_internal_option_key_is_rejected(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $service = app(FieldOptionMappingMutationService::class);

        $this->expectException(FieldMappingValidationException::class);
        $service->confirm($account, $configuration->id, $mapping->id, 'purple', '93');
    }

    #[Test]
    public function stale_mutation_after_configuration_revision_change_is_rejected(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $service = app(FieldOptionMappingMutationService::class);
        $configurationModel = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $staleContext = new FieldOptionMappingMutationContext(
            configurationRevision: 'stale-revision-token',
            fieldMappingId: $mapping->id,
            externalFieldKey: 'color',
            internalOptionKey: 'blue',
            existingOptionMappingId: FieldOptionMapping::withoutWorkspaceScope()
                ->where('field_mapping_id', $mapping->id)
                ->where('internal_option_key', 'blue')
                ->value('id'),
            existingExternalOptionValue: '93',
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('assertMutationContextStillCurrent');
        $method->setAccessible(true);

        $this->expectException(FieldOptionMappingStaleMutationException::class);
        $method->invoke($service, $configurationModel->refresh(), $staleContext);
    }

    #[Test]
    public function external_option_validator_runs_outside_active_transaction(): void
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();
        $levelBefore = DB::transactionLevel();
        $validator = $this->bindTransactionAwareValidator();

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '93',
        );

        $this->assertSame($levelBefore, $validator->transactionLevelAtValidate);
    }

    #[Test]
    public function missing_option_mapping_manage_permission_shows_configure_action_with_url(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

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

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.actions.configure_option_mapping'), $destination['action_label']);
        $this->assertStringContainsString(
            ManageSyncFieldOptionMappings::getUrl([
                'account' => $account->id,
                'configuration' => $configuration->id,
                'mapping' => $mapping->id,
            ]),
            (string) $destination['action_url'],
        );
    }

    #[Test]
    public function missing_option_mapping_view_permission_shows_view_only_action_with_url(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);

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

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.actions.view_option_mapping'), $destination['action_label']);
        $this->assertStringContainsString(
            ManageSyncFieldOptionMappings::getUrl([
                'account' => $account->id,
                'configuration' => $configuration->id,
                'mapping' => $mapping->id,
            ]),
            (string) $destination['action_url'],
        );
    }

    #[Test]
    public function missing_option_mapping_without_permission_shows_permission_required(): void
    {
        [$workspace, $account, $configuration, $actor, $binding] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

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

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.status.permission_required'));
    }

    #[Test]
    public function missing_option_mapping_change_marks_remediation_configuration_changed(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

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
            'external_option_value' => '93',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.status.configuration_changed'), $destination['status_message']);
    }

    #[Test]
    public function external_option_missing_or_stale_manage_permission_shows_configure_action_with_url(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::ExternalOptionMissingOrStale->value,
            'subject' => $binding->id,
            'context' => [
                'external_field_key' => 'color',
                'external_option_value' => '93',
            ],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [[
                    'internal_option_key' => 'blue',
                    'external_option_value' => '93',
                ]],
            ]],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.actions.configure_option_mapping'), $destination['action_label']);
        $this->assertStringContainsString(
            ManageSyncFieldOptionMappings::getUrl([
                'account' => $account->id,
                'configuration' => $configuration->id,
                'mapping' => $mapping->id,
            ]),
            (string) $destination['action_url'],
        );
    }

    #[Test]
    public function external_option_missing_or_stale_view_permission_shows_view_only_action_with_url(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::ExternalOptionMissingOrStale->value,
            'subject' => $binding->id,
            'context' => [
                'external_field_key' => 'color',
                'external_option_value' => '93',
            ],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [[
                    'internal_option_key' => 'blue',
                    'external_option_value' => '93',
                ]],
            ]],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.actions.view_option_mapping'), $destination['action_label']);
        $this->assertStringContainsString(
            ManageSyncFieldOptionMappings::getUrl([
                'account' => $account->id,
                'configuration' => $configuration->id,
                'mapping' => $mapping->id,
            ]),
            (string) $destination['action_url'],
        );
    }

    #[Test]
    public function external_option_missing_or_stale_without_permission_shows_permission_required(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::ExternalOptionMissingOrStale->value,
            'subject' => $binding->id,
            'context' => [
                'external_field_key' => 'color',
                'external_option_value' => '93',
            ],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [[
                    'internal_option_key' => 'blue',
                    'external_option_value' => '93',
                ]],
            ]],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.status.permission_required'), $destination['status_message']);
    }

    #[Test]
    public function external_option_missing_or_stale_change_marks_remediation_configuration_changed(): void
    {
        [$workspace, $account, $configuration, $actor, $binding, $mapping] = $this->previewRemediationFixture([
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $this->createCompletedRunWithFinding($workspace, $configuration, $actor, [
            'code' => SyncPreviewFindingCode::ExternalOptionMissingOrStale->value,
            'subject' => $binding->id,
            'context' => [
                'external_field_key' => 'color',
                'external_option_value' => '93',
            ],
        ], [
            'field_mappings' => [[
                'field_binding_id' => $binding->id,
                'external_field_key' => 'color',
                'option_mappings' => [[
                    'internal_option_key' => 'blue',
                    'external_option_value' => '93',
                ]],
            ]],
        ]);

        FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)
            ->where('internal_option_key', 'blue')
            ->update(['external_option_value' => '94']);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $this->assertNotEmpty($component->instance()->worklistRows);
        $this->assertNotEmpty($component->instance()->worklistRows[0]['findings']);

        $destination = $this->destinationForLabel(
            $component->instance()->worklistRows,
            __('sync_preview.remediation.option_mapping'),
        );

        $this->assertNotNull($destination);
        $this->assertSame(__('sync_preview.status.configuration_changed'), $destination['status_message']);
    }

    #[Test]
    public function manage_sync_field_mappings_shows_option_values_link_for_eligible_color_mapping(): void
    {
        [$account, $configuration] = $this->colorMappingFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertSeeHtml('data-testid="sync-mapping-option-values"')
            ->assertSee(__('sync_mappings.actions.option_values'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($workspace, $actor, $permissions);

        return $actor;
    }

    /**
     * @return array{0: ConnectorAccount, 1: SyncConfiguration, 2: FieldMapping}
     */
    private function colorMappingFixture(): array
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'color' => [
                ['value' => '93', 'label' => 'Blue'],
                ['value' => '94', 'label' => 'Pink'],
            ],
        ]);

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

        return [$account, $configuration, $mapping];
    }

    /**
     * @return array{0: ConnectorAccount, 1: SyncConfiguration, 2: FieldMapping}
     */
    private function orphanGreenFixture(): array
    {
        [$account, $configuration, $mapping] = $this->colorMappingFixture();

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'green',
            'external_option_value' => '95',
        ]);

        return [$account, $configuration, $mapping];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration, 3: User, 4: FieldBinding, 5: FieldMapping}
     */
    private function previewRemediationFixture(array $permissions): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $configuration = app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'color' => [
                ['value' => '93', 'label' => 'Blue'],
                ['value' => '94', 'label' => 'Pink'],
            ],
        ]);

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

        $actor = $this->actorWithPermissions($permissions);

        return [$workspace, $account, $configuration, $actor, $binding, $mapping];
    }

    /**
     * @return array<string, string>
     */
    private function optionPageParameters(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        FieldMapping $mapping,
    ): array {
        return [
            'account' => $account->id,
            'configuration' => $configuration->id,
            'mapping' => $mapping->id,
        ];
    }

    private function bindTransactionAwareValidator(): TransactionAwareTestFieldOptionMappingOptionValidator
    {
        $validator = new TransactionAwareTestFieldOptionMappingOptionValidator(
            app(FieldDefinitionInternalOptionValidator::class),
        );

        $profiles = config('connectors.profiles', []);
        $profiles['test_sync_support'] = $this->test_sync_support_profile_config([
            'field_option_mapping_validator' => TransactionAwareTestFieldOptionMappingOptionValidator::class,
        ]);

        $container = app(Container::class);
        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry($container, $profiles));
        $container->instance(TransactionAwareTestFieldOptionMappingOptionValidator::class, $validator);

        return $validator;
    }

    private function bindCountingValidator(): CountingTestFieldOptionMappingOptionValidator
    {
        $validator = new CountingTestFieldOptionMappingOptionValidator(
            app(FieldDefinitionInternalOptionValidator::class),
        );

        $profiles = config('connectors.profiles', []);
        $profiles['test_sync_support'] = $this->test_sync_support_profile_config([
            'field_option_mapping_validator' => CountingTestFieldOptionMappingOptionValidator::class,
        ]);

        $container = app(Container::class);
        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry($container, $profiles));
        $container->instance(CountingTestFieldOptionMappingOptionValidator::class, $validator);

        return $validator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function test_sync_support_profile_config(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => TestSyncPreviewCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ], $overrides);
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
    ): SyncRun {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-FIND',
            'name' => 'Finding Product',
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
}
