<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class ConnectorAccountGap025aPermissionMatrixTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        App::setLocale('uk');
        $this->enableSchemaDiscoveryCapability();
        Config::set('connectors.discovery.manual_trigger_enabled', true);
    }

    #[Test]
    public function run_connector_discovery_only_actor_can_refresh_available_fields_but_not_open_snapshot(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $snapshot = $this->createSucceededSnapshot($account);

        $authorization = app(ConnectorAuthorization::class);
        $this->assertTrue($authorization->canSafeRead($actor, $workspace));
        $this->assertTrue($authorization->canDiscoveryControl($actor, $workspace));
        $this->assertFalse($authorization->canReadSyncMappings($actor, $workspace));
        $this->assertFalse($authorization->canLayerBExternalFieldReference($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('runDiscovery');

        $this->actingAs($actor)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $account,
                'snapshot' => $snapshot,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function view_connector_accounts_only_actor_can_view_overview_but_not_refresh_or_open_snapshot(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $snapshot = $this->createSucceededSnapshot($account);

        $authorization = app(ConnectorAuthorization::class);
        $this->assertTrue($authorization->canSafeRead($actor, $workspace));
        $this->assertFalse($authorization->canDiscoveryControl($actor, $workspace));
        $this->assertFalse($authorization->canReadSyncMappings($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('runDiscovery');

        $this->assertFalse($actor->can('viewRunDiscovery', $account));

        $this->actingAs($actor)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $account,
                'snapshot' => $snapshot,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function manage_connector_accounts_only_actor_matches_frozen_discovery_control_matrix(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);
        $account = $this->createConnectorAccount($workspace);

        $authorization = app(ConnectorAuthorization::class);
        $this->assertTrue($authorization->canManage($actor, $workspace));
        $this->assertTrue($authorization->canDiscoveryControl($actor, $workspace));
        $this->assertFalse($authorization->canReadSyncMappings($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('runDiscovery')
            ->assertActionEnabled('runConnectionCheck');
    }

    #[Test]
    public function view_sync_mappings_only_actor_can_open_available_fields_with_layer_b_copy(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $snapshot = $this->createSucceededSnapshot($account);

        $authorization = app(ConnectorAuthorization::class);
        $this->assertFalse($authorization->canSafeRead($actor, $workspace));
        $this->assertTrue($authorization->canReadSyncMappings($actor, $workspace));
        $this->assertTrue($authorization->canLayerBExternalFieldReference($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSuccessful()
            ->assertSee(__('sync_mappings.available_fields_title', [
                'platform' => $account->connectorDefinition->name,
            ]))
            ->assertDontSee(__('connectors.ui.snapshot.title'));

        $this->actingAs($actor)
            ->get(ConnectorAccountResource::getUrl('view', ['record' => $account]))
            ->assertForbidden();
    }

    #[Test]
    public function manage_sync_mappings_only_actor_can_open_available_fields_without_connector_authority(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $snapshot = $this->createSucceededSnapshot($account);

        $authorization = app(ConnectorAuthorization::class);
        $this->assertFalse($authorization->canSafeRead($actor, $workspace));
        $this->assertFalse($authorization->canDiscoveryControl($actor, $workspace));
        $this->assertTrue($authorization->canReadSyncMappings($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSuccessful()
            ->assertSee(__('sync_mappings.available_fields_title', [
                'platform' => $account->connectorDefinition->name,
            ]));

        $this->actingAs($actor)
            ->get(ConnectorAccountResource::getUrl('view', ['record' => $account]))
            ->assertForbidden();
    }

    #[Test]
    public function combined_mapping_and_connector_permissions_keep_layer_b_available_fields_presentation(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $snapshot = $this->createSucceededSnapshot($account);

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSuccessful()
            ->assertSee(__('sync_mappings.available_fields_title', [
                'platform' => $account->connectorDefinition->name,
            ]))
            ->assertDontSee(__('connectors.ui.snapshot.title'))
            ->assertDontSee(__('connectors.ui.columns.snapshot_state'))
            ->assertDontSee(__('connectors.ui.columns.source'));
    }

    private function createSucceededSnapshot(ConnectorAccount $account): ConnectorSchemaSnapshot
    {
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1200,
            'user_message_key' => null,
            'technical_summary' => null,
            'error_code' => null,
            'snapshot_id' => null,
            'previous_snapshot_id' => null,
            'created_at' => now(),
        ]);

        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => '1.0',
            'field_count' => 5,
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'captured_at' => now(),
        ]);
    }
}
