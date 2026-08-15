<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    public function no_discovery_state_is_read_only_without_remove_controls(): void
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
            'external_field_key' => 'legacy_key',
        ]);

        $actor = $this->actorWithPermissions([WorkspacePermissions::MANAGE_SYNC_MAPPINGS]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee(__('sync_mappings.no_discovery_notice', ['platform' => $account->connectorDefinition->name]))
            ->assertSee('legacy_key')
            ->assertDontSee(__('sync_mappings.actions.remove'))
            ->assertDontSee(__('sync_mappings.actions.confirm'));
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
}
