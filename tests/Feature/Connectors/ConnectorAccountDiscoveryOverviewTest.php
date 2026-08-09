<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\DiscoveryRunsRelationManager;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
use App\Support\Connectors\ConnectorUiFormatter;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionReason;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class ConnectorAccountDiscoveryOverviewTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    private const SENSITIVE_CANARY = 'DISCOVERY_SENSITIVE_CANARY_4B2B';

    private ?ConnectorDiscoveryDispatchPortStub $dispatchStub = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        $this->enableSchemaDiscoveryCapability();

        $this->dispatchStub = new ConnectorDiscoveryDispatchPortStub;
        $this->app->instance(ConnectorDiscoveryDispatchPort::class, $this->dispatchStub);
    }

    #[Test]
    public function discovery_summary_shows_never_discovered_empty_state(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.discovery.empty_state'));
    }

    #[Test]
    public function discovery_summary_shows_active_runtime_with_polling(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Running);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(__('connectors.ui.runtime.discovery_running'));

        $this->assertStringContainsString('wire:poll.5s="refreshDiscoveryState"', $component->html());
    }

    #[Test]
    public function discovery_summary_shows_succeeded_state_with_snapshot_details(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
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

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(__('connectors.enums.discovery_run_status.succeeded'))
            ->assertSee('42')
            ->assertSee(__('connectors.ui.snapshot.first_snapshot'))
            ->assertSee(__('connectors.ui.snapshot.view_summary'));
    }

    #[Test]
    public function discovery_summary_shows_failed_safe_error_only(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
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
    }

    #[Test]
    public function discovery_summary_shows_no_change_snapshot_state(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
        ]);
        $hash = hash('sha256', 'same-hash');
        $previousRun = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now()->subHour(),
        ]);
        $previousSnapshot = $this->createSnapshotForRun($previousRun, [
            'canonical_hash' => $hash,
        ]);
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);
        $snapshot = $this->createSnapshotForRun($run, [
            'previous_snapshot_id' => $previousSnapshot->id,
            'canonical_hash' => $hash,
        ]);
        $run->update(['snapshot_id' => $snapshot->id]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSee(__('connectors.ui.snapshot.no_change'));
    }

    #[Test]
    public function terminal_discovery_state_does_not_poll(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_discovery_at' => now(),
        ]);
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()]);

        $this->assertStringNotContainsString('wire:poll.5s="refreshDiscoveryState"', $component->html());
    }

    #[Test]
    public function discovery_history_lists_account_runs_in_newest_first_order(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $older = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'created_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);
        $newer = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Failed, [
            'created_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'user_message_key' => 'connectors.errors.discovery_failed',
            'technical_summary' => self::SENSITIVE_CANARY,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(DiscoveryRunsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ]);

        $component->assertCanSeeTableRecords([$newer, $older]);
        $this->assertSensitiveFieldsAbsent($component);
        $this->assertSame([20, 50, 100], $component->instance()->getTable()->getPaginationPageOptions());
    }

    #[Test]
    public function discovery_history_excludes_cross_workspace_runs(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = $this->createConnectorAccount($foreignWorkspace);
        $foreignRun = $this->createDiscoveryRun($foreignAccount, ConnectorDiscoveryRunStatus::Succeeded);

        Livewire::actingAs($admin)
            ->test(DiscoveryRunsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertDontSee((string) $foreignRun->id);
    }

    #[Test]
    public function merchandiser_can_see_discovery_history_but_not_connection_checks(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount();
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);

        $detailComponent = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.sections.discovery'))
            ->assertDontSee(__('connectors.ui.relation.connection_checks'));

        Livewire::actingAs($merchandiser)
            ->test(DiscoveryRunsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertSee(__('connectors.ui.relation.discovery_runs'));

        $relationManagers = (new \ReflectionMethod(ViewConnectorAccount::class, 'getAllRelationManagers'))
            ->invoke($detailComponent->instance());

        $this->assertSame([DiscoveryRunsRelationManager::class], $relationManagers);
    }

    #[Test]
    public function snapshot_detail_is_accessible_for_same_workspace_account(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 15,
            'canonical_hash' => hash('sha256', self::SENSITIVE_CANARY),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSee('15')
            ->assertSee(__('connectors.ui.snapshot.first_snapshot'));

        $this->assertSensitiveFieldsAbsent($component);
    }

    #[Test]
    public function foreign_account_snapshot_is_not_found(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $foreignAccount = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($foreignAccount, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run);

        $this->actingAs($admin)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $account,
                'snapshot' => $snapshot,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function foreign_workspace_snapshot_is_not_found(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = $this->createConnectorAccount($foreignWorkspace);
        $run = $this->createDiscoveryRun($foreignAccount, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run);

        $this->actingAs($admin)
            ->get(ConnectorAccountResource::getUrl('view-snapshot', [
                'record' => $foreignAccount,
                'snapshot' => $snapshot,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function run_discovery_action_is_hidden_when_manual_trigger_disabled(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', false);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDoesNotExist('runDiscovery');
    }

    #[Test]
    public function run_discovery_action_is_available_when_enabled_and_supported(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionEnabled('runDiscovery');
    }

    #[Test]
    public function run_discovery_action_disabled_when_account_disabled(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: ['is_enabled' => false]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionExists('runDiscovery')
            ->assertActionDisabled('runDiscovery')
            ->assertSee(__('connectors.ui.disabled_reasons.account_disabled'));
    }

    #[Test]
    public function run_discovery_action_not_visible_for_actor_without_discovery_eligibility(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $manager = $this->createStaffUser(UserRole::Manager);
        $account = $this->createConnectorAccount();

        $this->assertFalse($manager->can('viewRunDiscovery', $account));

        Livewire::actingAs($manager)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function run_discovery_execution_denied_when_account_disabled_before_action(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionEnabled('runDiscovery');

        $account->update(['is_enabled' => false]);

        $this->assertTrue($component->instance()->record->is_enabled);
        $this->assertFalse($admin->can('runDiscovery', $account->fresh()));

        $this->dispatchStub->executeManualThrowable = new \RuntimeException('SHOULD_NOT_DISPATCH');

        $component->callAction('runDiscovery');

        $this->assertSame(0, $this->dispatchStub->callCount);
        $html = $component->html();
        $this->assertStringContainsString(
            __('connectors.errors.account_disabled', locale: 'uk'),
            $html,
        );
        $this->assertStringNotContainsString(
            __('connectors.ui.notifications.action_failed', locale: 'uk'),
            $html,
        );
    }

    #[Test]
    public function run_discovery_execution_denied_when_eligibility_lost_before_action(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $manager = $this->createStaffUser(UserRole::Manager);
        $manager->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        $account = $this->createConnectorAccount();

        $component = Livewire::actingAs($manager)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionEnabled('runDiscovery');

        $manager->revokePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
        $manager->refresh();

        $this->assertTrue($account->fresh()->is_enabled);
        $this->assertFalse($manager->can('viewRunDiscovery', $account));
        $this->assertFalse($manager->can('runDiscovery', $account));

        $this->dispatchStub->executeManualThrowable = new \RuntimeException('SHOULD_NOT_DISPATCH');

        $component->call('mountAction', 'runDiscovery');

        $this->assertSame(0, $this->dispatchStub->callCount);

        $html = $component->html();
        $this->assertStringNotContainsString(
            __('connectors.errors.account_disabled', locale: 'uk'),
            $html,
        );
    }

    #[Test]
    public function run_discovery_action_disabled_when_profile_missing(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: ['auth_profile' => 'missing_profile']);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDisabled('runDiscovery');
    }

    #[Test]
    public function run_discovery_action_disabled_when_capability_unsupported(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        Config::set('connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities', [
            'connection_check',
        ]);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDisabled('runDiscovery');
    }

    #[Test]
    public function run_discovery_action_disabled_when_active_run_exists(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Running);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertActionDisabled('runDiscovery')
            ->assertSee(__('connectors.ui.actions.discovery_already_active'));
    }

    #[Test]
    public function run_discovery_action_disabled_when_source_unavailable(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->update(['endpoint_path' => null]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertActionDisabled('runDiscovery');
    }

    #[Test]
    public function run_discovery_action_executes_through_dispatch_boundary(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $this->dispatchStub->executeManualCallback = function () use ($account): void {
            $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Queued);
        };

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runDiscovery')
            ->assertNotified();

        $this->assertSame(1, $this->dispatchStub->callCount);
    }

    #[Test]
    public function run_discovery_backend_rejection_shows_safe_notification(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $this->dispatchStub->executeManualThrowable = new ConnectorDiscoverySourceResolutionException(
            ConnectorDiscoverySourceResolutionReason::Missing,
            0,
        );

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runDiscovery')
            ->assertNotified();

        $this->assertSame(1, $this->dispatchStub->callCount);
    }

    #[Test]
    public function merchandiser_can_run_discovery_when_flag_enabled(): void
    {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionEnabled('runDiscovery');
    }

    #[Test]
    public function list_shows_last_successful_discovery_column(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $discoveredAt = now()->subDay()->startOfMinute();
        $account = $this->createConnectorAccount(overrides: [
            'name' => 'Discovery List Account',
            'last_successful_discovery_at' => $discoveredAt,
        ]);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertSee(ConnectorUiFormatter::formatDateTime($discoveredAt));
    }

    #[Test]
    public function discovery_localization_keys_exist_in_all_locales(): void
    {
        $locales = ['en', 'uk', 'ru'];
        $keys = [
            ...array_map(fn (ConnectorDiscoveryRunStatus $case) => $case->label(), ConnectorDiscoveryRunStatus::cases()),
            ...array_map(fn (ConnectorDiscoveryRunTrigger $case) => $case->label(), ConnectorDiscoveryRunTrigger::cases()),
            'connectors.ui.sections.discovery',
            'connectors.ui.actions.run_discovery',
            'connectors.ui.runtime.discovery_waiting',
            'connectors.ui.runtime.discovery_running',
            'connectors.ui.notifications.discovery_started',
            'connectors.ui.notifications.discovery_reused',
            'connectors.ui.snapshot.first_snapshot',
            'connectors.ui.snapshot.no_change',
            'connectors.ui.relation.discovery_runs',
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

final class ConnectorDiscoveryDispatchPortStub
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
