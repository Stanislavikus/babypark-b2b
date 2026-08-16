<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\ConnectionChecksRelationManager;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\DiscoveryRunsRelationManager;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
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

class ConnectorAccountGap025aCutoverTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    private ?Gap025aDiscoveryDispatchPortStub $dispatchStub = null;

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

        $this->dispatchStub = new Gap025aDiscoveryDispatchPortStub;
        $this->app->instance(ConnectorDiscoveryDispatchPort::class, $this->dispatchStub);
    }

    #[Test]
    public function merchant_account_overview_registers_no_relation_managers(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $relationManagers = (new \ReflectionMethod(ViewConnectorAccount::class, 'getAllRelationManagers'))
            ->invoke(Livewire::actingAs($admin)
                ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
                ->instance());

        $this->assertSame([], $relationManagers);
    }

    #[Test]
    public function discovery_and_connection_check_relation_manager_classes_remain_available(): void
    {
        $this->assertTrue(class_exists(DiscoveryRunsRelationManager::class));
        $this->assertTrue(class_exists(ConnectionChecksRelationManager::class));
    }

    #[Test]
    public function layer_b_external_field_reference_requires_mapping_permission_only(): void
    {
        $connectorOnly = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $connectorOnly);

        $mappingViewer = $this->createStaffUser(UserRole::Manager);
        $this->grantSyncMappingsView($this->defaultWorkspace(), $mappingViewer);

        $authorization = app(ConnectorAuthorization::class);
        $workspace = $this->defaultWorkspace();

        $this->assertFalse($authorization->canLayerBExternalFieldReference($connectorOnly, $workspace));
        $this->assertTrue($authorization->canLayerBExternalFieldReference($mappingViewer, $workspace));
    }

    #[Test]
    public function account_overview_uses_available_fields_section_title(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.sections.available_fields'))
            ->assertDontSee(__('connectors.ui.sections.discovery'));
    }

    #[Test]
    public function account_overview_forbids_layer_c_vocabulary_in_rendered_content(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
        ]);
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);
        $snapshot = $this->createSnapshotForRun($run, ['field_count' => 9]);
        $run->update(['snapshot_id' => $snapshot->id]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()]);

        $forbidden = [
            __('connectors.ui.columns.source'),
            __('connectors.ui.columns.snapshot_state'),
            __('connectors.ui.columns.captured_at'),
            __('connectors.ui.snapshot.view_summary'),
            __('connectors.ui.snapshot.first_snapshot'),
            __('connectors.ui.snapshot.no_change'),
            __('connectors.ui.sections.discovery'),
            __('connectors.ui.actions.run_discovery'),
            'Знімок',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $component->html(), "Forbidden vocabulary [{$needle}] leaked.");
        }
    }

    #[Test]
    public function refresh_available_fields_action_uses_layer_b_label_and_notifications(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $this->dispatchStub->executeManualCallback = function () use ($account): void {
            $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Queued);
        };

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionHasLabel('runDiscovery', __('connectors.ui.actions.refresh_available_fields'))
            ->callAction('runDiscovery')
            ->assertNotified(__('connectors.ui.notifications.available_fields_refresh_started'));
    }

    #[Test]
    public function manual_discovery_action_state_uses_available_fields_vocabulary(): void
    {
        $account = $this->createConnectorAccount();
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Running);

        $state = app(ConnectorAccountUiState::class)->manualDiscoveryActionState($account->fresh());

        $this->assertSame(__('connectors.ui.actions.available_fields_refresh_active'), $state['label']);
        $this->assertSame(__('connectors.ui.disabled_reasons.available_fields_refresh_active'), $state['disabled_reason']);
        $this->assertFalse($state['enabled']);
    }

    #[Test]
    public function snapshot_page_always_uses_layer_b_title_for_mapping_authorized_actor(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $this->grantSyncMappingsView($this->defaultWorkspace(), $admin);
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run);

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSee(__('sync_mappings.available_fields_title', [
                'platform' => $account->connectorDefinition->name,
            ]))
            ->assertDontSee(__('connectors.ui.snapshot.title'));
    }

    #[Test]
    public function snapshot_route_returns_forbidden_without_mapping_permission(): void
    {
        $connectorOnly = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $connectorOnly);
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run);

        $this->actingAs($connectorOnly)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $account,
                'snapshot' => $snapshot,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function account_overview_does_not_link_to_snapshot_detail(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
        ]);
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);
        $snapshot = $this->createSnapshotForRun($run, ['field_count' => 5]);
        $run->update(['snapshot_id' => $snapshot->id]);

        $url = ConnectorAccountResource::getUrl('view-snapshot', [
            'record' => $account,
            'snapshot' => $snapshot,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertDontSee($url)
            ->assertDontSee(__('connectors.ui.snapshot.view_summary'));
    }

    #[Test]
    public function gap025a_localization_keys_exist_in_all_locales(): void
    {
        $locales = ['en', 'uk', 'ru'];
        $keys = [
            'connectors.ui.sections.available_fields',
            'connectors.ui.actions.refresh_available_fields',
            'connectors.ui.actions.available_fields_refresh_active',
            'connectors.ui.available_fields.never_checked',
            'connectors.ui.available_fields.refreshing',
            'connectors.ui.available_fields.checked_at',
            'connectors.ui.available_fields.field_count',
            'connectors.ui.notifications.available_fields_refresh_started',
            'connectors.ui.notifications.available_fields_refresh_reused',
            'connectors.ui.notifications.available_fields_refresh_failed',
            'connectors.ui.disabled_reasons.available_fields_refresh_active',
        ];

        foreach ($locales as $locale) {
            $translations = json_decode(
                file_get_contents(base_path("lang/{$locale}.json")),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$key} in {$locale}");
                $this->assertNotSame($key, __($key, locale: $locale));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDiscoveryRun(
        ConnectorAccount $account,
        ConnectorDiscoveryRunStatus $status,
        array $overrides = [],
    ): ConnectorDiscoveryRun {
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => $status,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => now(),
            'finished_at' => in_array($status, [
                ConnectorDiscoveryRunStatus::Succeeded,
                ConnectorDiscoveryRunStatus::Failed,
            ], true) ? now() : null,
            'duration_ms' => 1200,
            'user_message_key' => null,
            'technical_summary' => null,
            'error_code' => null,
            'snapshot_id' => null,
            'previous_snapshot_id' => null,
            'created_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSnapshotForRun(ConnectorDiscoveryRun $run, array $overrides = []): ConnectorSchemaSnapshot
    {
        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'connector_account_id' => $run->connector_account_id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => '1.0',
            'field_count' => 10,
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'captured_at' => now(),
        ], $overrides));
    }
}

final class Gap025aDiscoveryDispatchPortStub
{
    public int $callCount = 0;

    public ?\Closure $executeManualCallback = null;

    public ?\Throwable $executeManualThrowable = null;

    public function executeManual(User $actor, string $workspaceId, string $connectorAccountId): ConnectorDiscoveryDispatchDecision
    {
        $this->callCount++;

        if ($this->executeManualThrowable !== null) {
            throw $this->executeManualThrowable;
        }

        if ($this->executeManualCallback !== null) {
            ($this->executeManualCallback)($actor, $workspaceId, $connectorAccountId);
        }

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('connector_account_id', $connectorAccountId)
            ->latest('created_at')
            ->first();

        if ($run === null) {
            throw new \RuntimeException('Unexpected executeManual call in GAP-025A dispatch stub.');
        }

        return ConnectorDiscoveryDispatchDecision::dispatch($run->id, now()->addHour()->getTimestamp());
    }
}
