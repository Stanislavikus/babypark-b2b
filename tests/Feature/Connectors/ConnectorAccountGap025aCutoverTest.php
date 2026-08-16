<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
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
use App\Models\WorkspaceUser;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class ConnectorAccountGap025aCutoverTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    private const SENSITIVE_CANARY = 'GAP025A_SENSITIVE_CANARY_4B2B';

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
    public function overview_removes_relation_managers_from_merchant_surface(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $relationManagers = (new \ReflectionMethod(ViewConnectorAccount::class, 'getAllRelationManagers'))
            ->invoke($component->instance());

        $this->assertSame([], $relationManagers);
        $component
            ->assertDontSee(__('connectors.ui.relation.discovery_runs'))
            ->assertDontSee(__('connectors.ui.relation.connection_checks'));
    }

    #[Test]
    public function backend_relation_managers_remain_instantiable_for_layer_c(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(DiscoveryRunsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertSee(__('connectors.ui.relation.discovery_runs'))
            ->assertCanSeeTableRecords([$run]);

        Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertSee(__('connectors.ui.relation.connection_checks'));
    }

    #[Test]
    public function available_fields_summary_shows_never_checked_state(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.sections.available_fields'))
            ->assertSee(__('connectors.ui.available_fields.never_checked'));
    }

    #[Test]
    public function available_fields_summary_shows_refreshing_state_with_polling(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Running);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(__('connectors.ui.available_fields.refreshing'));

        $this->assertStringContainsString('wire:poll.5s="refreshDiscoveryState"', $component->html());
    }

    #[Test]
    public function available_fields_summary_shows_success_state_without_layer_c_metadata(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
        ]);
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 42,
            'canonical_hash' => hash('sha256', 'snapshot-a'),
        ]);
        $run->update(['snapshot_id' => $snapshot->id]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(trans_choice('connectors.ui.available_fields.count', 42, ['count' => 42]));

        $this->assertSensitiveFieldsAbsent($component);
        $this->assertForbiddenMerchantVocabularyAbsent($component);
    }

    #[Test]
    public function available_fields_summary_shows_failure_state_with_safe_message_only(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
        ]);
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Failed, [
            'finished_at' => now(),
            'user_message_key' => 'connectors.errors.discovery_failed',
            'technical_summary' => self::SENSITIVE_CANARY,
            'error_code' => 'discovery_vendor_timeout',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(__('connectors.errors.discovery_failed', locale: 'uk'));

        $this->assertSensitiveFieldsAbsent($component);
        $this->assertForbiddenMerchantVocabularyAbsent($component);
    }

    #[Test]
    public function view_only_actor_cannot_refresh_available_fields(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);

        $viewer = $this->viewOnlyActor();
        $account = $this->createConnectorAccount();

        Livewire::actingAs($viewer)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('runDiscovery');
    }

    #[Test]
    public function discovery_only_actor_can_refresh_available_fields(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);

        $actor = $this->discoveryOnlyActor();
        $account = $this->createConnectorAccount();

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertActionEnabled('runDiscovery');
    }

    #[Test]
    public function mapping_only_actor_cannot_open_connector_overview(): void
    {
        $actor = $this->mappingOnlyActor();
        $account = $this->createConnectorAccount();

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function connector_only_actor_receives_forbidden_on_snapshot_route(): void
    {
        $actor = $this->connectorOnlyActor();
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 12,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $account,
                'snapshot' => $snapshot,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function mapping_actor_receives_layer_b_available_fields_presentation(): void
    {
        $actor = $this->mappingOnlyActor();
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 15,
            'canonical_hash' => hash('sha256', self::SENSITIVE_CANARY),
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertOk()
            ->assertSee(__('sync_mappings.available_fields_title', ['platform' => $account->connectorDefinition->name]))
            ->assertDontSee(__('connectors.ui.columns.snapshot_state'))
            ->assertDontSee(__('connectors.ui.columns.source'))
            ->assertDontSee(__('connectors.ui.snapshot.title'))
            ->assertDontSee('Discovery');

        $this->assertSensitiveFieldsAbsent($component);
    }

    #[Test]
    public function combined_permissions_keep_layer_b_available_fields_presentation(): void
    {
        $actor = $this->combinedMappingAndConnectorActor();
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 8,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertOk()
            ->assertSee(__('sync_mappings.available_fields_title', ['platform' => $account->connectorDefinition->name]))
            ->assertDontSee(__('connectors.ui.snapshot.title'))
            ->assertDontSee(__('connectors.ui.columns.snapshot_state'))
            ->assertDontSee(__('connectors.ui.columns.source'));
    }

    #[Test]
    public function mapping_page_exposes_available_fields_entry_point_link(): void
    {
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
        ]);

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $snapshot = $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);
        $actor = $this->actorWithPermissions([
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        ]);

        $expectedUrl = ConnectorAccountResource::getUrl('view-snapshot', [
            'record' => $account->id,
            'snapshot' => $snapshot->id,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertOk()
            ->assertSeeHtml('data-testid="sync-mappings-available-fields-link"')
            ->assertSee(__('sync_mappings.available_fields_action', ['platform' => $account->connectorDefinition->name]))
            ->assertSee($expectedUrl);
    }

    #[Test]
    public function refresh_revocation_after_mount_fails_closed(): void
    {
        $actor = $this->mappingOnlyActor();
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run);
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $this->defaultWorkspace()->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $component = Livewire::actingAs($actor)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertOk();

        DB::table('workspace_user_roles')->where('workspace_user_id', $membership->id)->delete();

        $component->call('$refresh')->assertForbidden();
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
            'connectors.ui.available_fields.count',
            'connectors.ui.disabled_reasons.available_fields_refresh_active',
            'connectors.ui.notifications.available_fields_refresh_started',
            'connectors.ui.notifications.available_fields_refresh_reused',
            'connectors.ui.notifications.available_fields_refresh_failed',
            'sync_mappings.available_fields_action',
            'sync_mappings.available_fields_title',
            'sync_mappings.available_fields_last_checked',
            'sync_mappings.available_fields_count',
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

    private function viewOnlyActor(): User
    {
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($this->defaultWorkspace(), $user);

        return $user;
    }

    private function discoveryOnlyActor(): User
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $user);

        return $user;
    }

    private function mappingOnlyActor(): User
    {
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantSyncMappingsView($this->defaultWorkspace(), $user);

        return $user;
    }

    private function connectorOnlyActor(): User
    {
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorManage($this->defaultWorkspace(), $user);

        return $user;
    }

    private function combinedMappingAndConnectorActor(): User
    {
        return $this->actorWithPermissions([
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $workspace = $this->defaultWorkspace();
        $user = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Gap025a Actor '.Str::random(6),
            $permissions,
        );
        $this->assignRoleToMembership($membership, $role);

        return $user;
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
            'started_at' => in_array($status, [ConnectorDiscoveryRunStatus::Running, ConnectorDiscoveryRunStatus::Succeeded, ConnectorDiscoveryRunStatus::Failed], true)
                ? now()
                : null,
            'finished_at' => in_array($status, [ConnectorDiscoveryRunStatus::Succeeded, ConnectorDiscoveryRunStatus::Failed, ConnectorDiscoveryRunStatus::Cancelled], true)
                ? now()
                : null,
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

    private function assertForbiddenMerchantVocabularyAbsent(Testable $component): void
    {
        $forbidden = [
            'Discovery',
            'Знімок',
            'Snapshot',
            __('connectors.ui.columns.source'),
            __('connectors.ui.columns.snapshot_state'),
            __('connectors.ui.snapshot.view_summary'),
            __('connectors.ui.sections.discovery'),
            __('connectors.ui.enums.discovery_run_status.succeeded'),
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $component->html(), "Forbidden merchant vocabulary [{$needle}] leaked.");
        }
    }

    private function assertSensitiveFieldsAbsent(Testable $component): void
    {
        $surfaces = [
            $component->html(),
            json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            json_encode($component->effects, JSON_THROW_ON_ERROR),
        ];

        $forbidden = [
            self::SENSITIVE_CANARY,
            'endpoint_path',
            'canonical_hash',
            'technical_summary',
            'error_code',
            '/V1/products/attributes',
            'added_count',
            'changed_count',
            'removed_count',
            'unchanged_count',
        ];

        foreach ($forbidden as $needle) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($needle, $surface, "Sensitive value [{$needle}] leaked.");
            }
        }
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
            throw new \RuntimeException('Unexpected executeManual call in discovery dispatch stub.');
        }

        return ConnectorDiscoveryDispatchDecision::dispatch($run->id, now()->addHour()->getTimestamp());
    }
}
