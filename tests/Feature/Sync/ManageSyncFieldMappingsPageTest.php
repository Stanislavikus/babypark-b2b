<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Models\ConnectorAccount;
use App\Models\FieldBinding;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Services\Sync\CanonicalFieldMappingSuggestionProvider;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldMappingReadModelProjector;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ManageSyncFieldMappingsPageTest extends TestCase
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
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function mapping_page_is_not_registered_in_navigation(): void
    {
        $this->assertFalse(ManageSyncFieldMappings::shouldRegisterNavigation());
    }

    #[Test]
    public function connector_overview_does_not_embed_mapping_controls(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantConnectorManage($workspace, $actor);

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertDontSee(__('sync_mappings.title'))
            ->assertDontSee(__('sync_mappings.actions.confirm'));
    }

    #[Test]
    public function view_sync_mappings_allows_read_without_mutation_controls(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertOk()
            ->assertDontSee(__('sync_mappings.actions.confirm'))
            ->assertDontSee(__('sync_mappings.actions.remove'));
    }

    #[Test]
    public function manage_sync_mappings_allows_confirm_and_persists_mapping(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $binding = $this->productBinding('name');

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->call('confirmMapping', $binding->id, 'name')
            ->assertNotified(__('sync_mappings.notifications.confirmed'));

        $this->assertDatabaseHas('field_mappings', [
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'name',
        ]);
    }

    #[Test]
    public function suggestion_render_does_not_persist_until_confirm(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertOk();

        $this->assertSame(0, FieldMapping::withoutWorkspaceScope()->count());

        $component->call('$refresh');

        $this->assertSame(0, FieldMapping::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function permission_revocation_after_mount_fails_closed_on_refresh(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertOk();

        DB::table('workspace_user_roles')->where('workspace_user_id', $membership->id)->delete();

        $component->call('$refresh')->assertForbidden();
    }

    #[Test]
    public function stale_mutation_fails_after_permission_revocation(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $binding = $this->productBinding('name');
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ]);

        DB::table('workspace_user_roles')->where('workspace_user_id', $membership->id)->delete();

        $component->call('confirmMapping', $binding->id, 'name')->assertForbidden();
        $this->assertDatabaseMissing('field_mappings', ['field_binding_id' => $binding->id]);
    }

    #[Test]
    public function connector_manage_only_actor_cannot_access_mapping_page(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Connector Only',
            [WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($membership, $role);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function foreign_workspace_account_fails_closed(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = ConnectorAccount::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Foreign',
            'auth_profile' => $account->auth_profile,
            'base_url' => 'https://foreign.example.com',
            'store_code' => 'default',
            'is_enabled' => true,
            'settings' => ['secret' => 'value'],
            'credentials' => ['token' => 'secret'],
        ]);

        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $foreignAccount->id,
                'configuration' => $configuration->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function no_discovery_state_disables_confirm_change_and_choose_but_keeps_remove(): void
    {
        [$workspace, $account, $configuration, $binding, $externalFieldKey] = $this->noDiscoveryMappedFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee(__('sync_mappings.no_discovery_notice', ['platform' => $account->connectorDefinition->name]))
            ->assertSee($externalFieldKey)
            ->assertSeeHtml('data-testid="sync-mapping-remove"')
            ->assertDontSeeHtml('data-testid="sync-mapping-confirm"')
            ->assertDontSeeHtml('data-testid="sync-mapping-change"')
            ->assertDontSeeHtml('data-testid="sync-mapping-choose"');
    }

    #[Test]
    public function remove_succeeds_during_no_discovery_state(): void
    {
        [$workspace, $account, $configuration, $binding, $externalFieldKey] = $this->noDiscoveryMappedFixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->call('removeMapping', $binding->id, $externalFieldKey)
            ->assertNotified(__('sync_mappings.notifications.removed'))
            ->assertOk();

        $this->assertDatabaseMissing('field_mappings', [
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => $externalFieldKey,
        ]);
    }

    #[Test]
    public function mapping_only_actor_can_open_layer_b_available_fields_without_connector_secrets(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $snapshot = $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);
        $actor = $this->actorWithPermissions([WorkspacePermissions::VIEW_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->id,
            ])
            ->assertOk()
            ->assertSee(__('sync_mappings.available_fields_title', ['platform' => $account->connectorDefinition->name]))
            ->assertDontSee(__('connectors.ui.columns.snapshot_state'))
            ->assertDontSee(__('connectors.ui.columns.source'))
            ->assertDontSee('Discovery')
            ->assertDontSee('cs_live')
            ->assertDontSee('https://shop.example.com');
    }

    #[Test]
    public function combined_mapping_and_connector_permissions_keep_layer_b_available_fields_presentation(): void
    {
        [$workspace, $account] = $this->fixture();
        $snapshot = $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);
        $actor = $this->actorWithPermissions([
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->id,
            ])
            ->assertOk()
            ->assertSee(__('sync_mappings.available_fields_title', ['platform' => $account->connectorDefinition->name]))
            ->assertDontSee(__('connectors.ui.snapshot.title'))
            ->assertDontSee(__('connectors.ui.columns.snapshot_state'))
            ->assertDontSee(__('connectors.ui.columns.source'));
    }

    #[Test]
    public function stale_mapping_failure_shows_merchant_message_not_technical_exception_text(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $binding = $this->productBinding('name');

        $this->publishAuthoritativeSnapshot($account, ['field_x', 'field_y']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'field_x',
        );
        app(FieldMappingMutationService::class)->replace(
            $account,
            $configuration->id,
            $binding->id,
            'field_x',
            newExternalFieldKey: 'field_y',
        );

        $expectedBody = __('sync_mappings.errors.stale_state');

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->call('removeMapping', $binding->id, 'field_x')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('sync_mappings.notifications.failed'))
                    ->body($expectedBody),
            );

        $this->assertStringNotContainsString('SyncConfiguration', $expectedBody);
        $this->assertStringNotContainsString('FieldBinding', $expectedBody);
        $this->assertStringNotContainsString('FieldDefinition', $expectedBody);
        $this->assertStringNotContainsString($binding->id, $expectedBody);
        $this->assertStringNotContainsString($configuration->id, $expectedBody);
    }

    #[Test]
    public function rendered_interaction_markup_serializes_hostile_external_field_keys_safely(): void
    {
        $externalKey = "field'with\\quote";
        $registryPath = $this->bindHostileKeyCanonicalRegistry($externalKey);

        try {
            $workspace = $this->defaultWorkspace();
            $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
            $configuration = $this->createProductsSyncConfiguration($account);
            $this->publishAuthoritativeSnapshot($account, [$externalKey]);
            $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
            $mappedBinding = $this->productBinding('description');

            $component = Livewire::actingAs($actor)
                ->test(ManageSyncFieldMappings::class, [
                    'account' => $account->id,
                    'configuration' => $configuration->id,
                ]);

            $suggestedRow = collect($component->instance()->displayRows)
                ->first(fn (array $row) => $row['semantic_state'] === 'suggested'
                    && ($row['suggested_external_field_key'] ?? null) === $externalKey);

            $this->assertNotNull($suggestedRow);

            $jsSuggestedBindingId = Js::from($suggestedRow['field_binding_id']);
            $jsExternalKey = Js::from($externalKey);
            $jsChangeArguments = Js::from([
                'fieldBindingId' => $mappedBinding->id,
                'externalFieldKey' => $externalKey,
            ]);

            $suggestedHtml = $component->html();

            $this->assertStringContainsString(
                'confirmMapping('.$jsSuggestedBindingId.', '.$jsExternalKey.')',
                $suggestedHtml,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/wire:click="[^"]*confirmMapping\([^)]*\'field\'with/',
                $suggestedHtml,
            );

            app(FieldMappingMutationService::class)->confirm(
                $account,
                $configuration->id,
                $mappedBinding->id,
                $externalKey,
            );

            $mappedComponent = Livewire::actingAs($actor)
                ->test(ManageSyncFieldMappings::class, [
                    'account' => $account->id,
                    'configuration' => $configuration->id,
                ]);

            $mappedRow = collect($mappedComponent->instance()->displayRows)
                ->first(fn (array $row) => ($row['existing_external_field_key'] ?? null) === $externalKey);

            $this->assertNotNull($mappedRow);

            $mappedHtml = $mappedComponent->html();

            $this->assertStringContainsString(
                'removeMapping('.Js::from($mappedRow['field_binding_id']).', '.$jsExternalKey.')',
                $mappedHtml,
            );
            $this->assertStringContainsString(
                'mountAction(\'changeMapping\', '.$jsChangeArguments.')',
                $mappedHtml,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/wire:click="[^"]*(?:removeMapping|mountAction)[^"]*\'field\'with/',
                $mappedHtml,
            );
        } finally {
            if (File::isDirectory($registryPath)) {
                File::deleteDirectory($registryPath);
            }
        }
    }

    #[Test]
    public function change_mapping_with_hostile_current_external_key_replaces_exactly(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $binding = $this->productBinding('description');
        $externalKey = "field'with\\quote";
        $replacementKey = 'replacement_field_key';

        $this->publishAuthoritativeSnapshot($account, [$externalKey, $replacementKey]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            $externalKey,
        );

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->callAction(
                'changeMapping',
                data: ['external_field_key' => $replacementKey],
                arguments: [
                    'fieldBindingId' => $binding->id,
                    'externalFieldKey' => $externalKey,
                ],
            )
            ->assertNotified(__('sync_mappings.notifications.changed'));

        $this->assertDatabaseHas('field_mappings', [
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => $replacementKey,
        ]);
        $this->assertDatabaseMissing('field_mappings', [
            'field_binding_id' => $binding->id,
            'external_field_key' => $externalKey,
        ]);
        $this->assertSame(
            1,
            FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('field_binding_id', $binding->id)
                ->count(),
        );
    }

    #[Test]
    public function external_field_key_with_special_characters_is_passed_exactly_to_mutations(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);
        $binding = $this->productBinding('description');
        $externalKey = "field'with\\quote";

        $this->publishAuthoritativeSnapshot($account, [$externalKey]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->call('confirmMapping', $binding->id, $externalKey)
            ->assertNotified(__('sync_mappings.notifications.confirmed'));

        $this->assertDatabaseHas('field_mappings', [
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => $externalKey,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->call('removeMapping', $binding->id, $externalKey)
            ->assertNotified(__('sync_mappings.notifications.removed'));

        $this->assertDatabaseMissing('field_mappings', [
            'field_binding_id' => $binding->id,
            'external_field_key' => $externalKey,
        ]);
    }

    #[Test]
    public function foreign_sync_configuration_for_same_workspace_account_fails_closed(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $foreignAccount = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $foreignConfiguration = $this->createProductsSyncConfiguration($foreignAccount);
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $foreignConfiguration->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function random_sync_configuration_uuid_fails_closed(): void
    {
        [$workspace, $account] = $this->fixture();
        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => (string) Str::uuid(),
            ])
            ->assertNotFound();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor, true);
        $role = $this->createRoleWithPermissions($workspace->id, 'Mapping Actor', $permissions);
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration}
     */
    private function fixture(): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['name', 'sku', 'description']);

        return [$workspace, $account, $configuration];
    }

    private function bindHostileKeyCanonicalRegistry(string $externalKey): string
    {
        $tempRegistryPath = storage_path('framework/testing/canonical-registry-'.Str::random(8));
        File::ensureDirectoryExists($tempRegistryPath);

        $this->writeRegistryCsv($tempRegistryPath, 'canonical_product_fields.csv', [
            [
                'internal_code' => 'name',
                'canonical_english_name' => 'name',
                'uk_label' => 'name',
                'ru_label' => 'name',
                'description' => 'Test field',
                'implementation_kind' => 'core_model_property',
                'storage_owner' => 'Product',
                'field_definition_eligibility' => 'yes',
                'binding_strategy' => 'product',
                'scope' => 'system',
                'field_group_or_state' => 'basic_information',
                'data_type_or_state' => 'text',
                'value_shape' => 'scalar',
                'structure_schema_ref' => 'not_applicable',
                'is_localizable' => 'false',
                'value_localization_strategy' => 'not_localizable',
                'channel_value_strategy' => 'global_value',
                'inheritance_strategy' => 'none',
                'is_multi_value' => 'false',
                'unit_family' => 'not_applicable',
                'status' => 'active',
                'mvp_tier' => 'A',
                'default_enabled' => 'true',
                'verification_status' => 'verified',
                'recommended_action' => 'keep_as_is',
                'supports_admin_display' => 'true',
                'supports_b2b_display' => 'true',
                'supports_search' => 'true',
                'supports_filter' => 'true',
                'supports_table_column' => 'true',
                'evidence_subject_key' => 'field:name',
            ],
        ]);

        $this->writeRegistryCsv($tempRegistryPath, 'canonical_product_field_mappings.csv', [
            [
                'internal_code' => 'name',
                'channel' => 'adobe_commerce',
                'external_field' => $externalKey,
                'mapping_type' => 'direct',
                'transformation' => 'not_applicable',
                'applicability_id' => 'a001',
                'requirement_level' => 'required',
                'channel_schema_version' => '2.4.9-admin-rest',
                'verification_status' => 'verified',
                'evidence_subject_key' => 'mapping:adobe_commerce:name:'.$externalKey.':a001:2.4.9-admin-rest',
            ],
        ]);

        $reader = new CanonicalRegistryReader($tempRegistryPath);
        $this->app->instance(CanonicalRegistryReader::class, $reader);
        $this->app->instance(
            CanonicalFieldMappingSuggestionProvider::class,
            new CanonicalFieldMappingSuggestionProvider($reader),
        );
        $this->app->instance(
            FieldMappingReadModelProjector::class,
            new FieldMappingReadModelProjector(
                app(AuthoritativeConnectorSchemaSnapshotResolver::class),
                app(CanonicalFieldMappingSuggestionProvider::class),
            ),
        );

        return $tempRegistryPath;
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration, 3: FieldBinding, 4: string}
     */
    private function noDiscoveryMappedFixture(string $externalFieldKey = 'legacy_key'): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding('name');

        FieldMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => $externalFieldKey,
        ]);

        return [$workspace, $account, $configuration, $binding, $externalFieldKey];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeRegistryCsv(string $directory, string $filename, array $rows): void
    {
        $path = $directory.'/'.$filename;
        $handle = fopen($path, 'w');

        if ($rows === []) {
            fclose($handle);

            return;
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
