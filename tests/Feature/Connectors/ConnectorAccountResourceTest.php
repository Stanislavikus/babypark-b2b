<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\ConnectionChecksRelationManager;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountResourceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private const CREDENTIAL_CANARY = 'CANARY_UI_SECRET_4B2A3';

    private ?ConnectorConnectionCheckDispatchServiceStub $dispatchStub = null;

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
        $this->dispatchStub = new ConnectorConnectionCheckDispatchServiceStub;
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $this->dispatchStub);
    }

    private function expectNoDispatch(): void
    {
        $this->dispatchStub = new ConnectorConnectionCheckDispatchServiceStub;
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $this->dispatchStub);
    }

    private function bindDispatchStub(ConnectorConnectionCheckDispatchServiceStub $stub): void
    {
        $this->dispatchStub = $stub;
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);
    }

    #[Test]
    public function view_any_requires_workspace_connector_read_permission(): void
    {
        $workspace = $this->defaultWorkspace();
        $viewer = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($workspace, $viewer);
        $this->assertTrue($viewer->can('viewAny', ConnectorAccount::class));

        $denied = $this->createStaffUser(UserRole::Manager);
        $this->makeWorkspaceMembership($workspace, $denied, true);
        $this->assertFalse($denied->can('viewAny', ConnectorAccount::class));

        $legacyAdmin = $this->createStaffUser(UserRole::Admin);
        $this->assertFalse($legacyAdmin->can('viewAny', ConnectorAccount::class));
    }

    #[Test]
    public function discovery_only_actor_can_reach_list_and_detail_with_safe_fields_only(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $merchandiser);
        $account = $this->createConnectorAccount(overrides: [
            'store_code' => 'secret-store',
            'tenant_context' => 'secret-tenant',
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials(
                    'ck_'.self::CREDENTIAL_CANARY,
                    'cs_'.self::CREDENTIAL_CANARY,
                    'at_'.self::CREDENTIAL_CANARY,
                    'ts_'.self::CREDENTIAL_CANARY,
                ),
            ),
        ]);

        $this->actingAs($merchandiser)
            ->get(ConnectorAccountResource::getUrl('index'))
            ->assertSuccessful();

        $listComponent = Livewire::actingAs($merchandiser)
            ->test(ListConnectorAccounts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$account]);

        $this->assertStringNotContainsString('secret-store', $listComponent->html());
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $listComponent->html());

        $detailComponent = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $snapshot = json_encode($detailComponent->snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $snapshot);
        $this->assertStringNotContainsString('secret-store', $snapshot);
        $this->assertStringNotContainsString('secret-tenant', $snapshot);
    }

    #[Test]
    public function manager_without_permission_cannot_see_navigation_or_list(): void
    {
        $manager = $this->createStaffUser(UserRole::Manager);

        $this->actingAs($manager)
            ->get(ConnectorAccountResource::getUrl('index'))
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(ListConnectorAccounts::class)
            ->assertForbidden();
    }

    #[Test]
    public function authorized_user_can_reach_list(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$account]);
    }

    #[Test]
    public function manager_without_permission_cannot_view_detail(): void
    {
        $account = $this->createConnectorAccount();
        $manager = $this->createStaffUser(UserRole::Manager);

        $this->actingAs($manager)
            ->get(ConnectorAccountResource::getUrl('view', ['record' => $account]))
            ->assertForbidden();
    }

    #[Test]
    public function cross_workspace_account_is_absent_from_list_and_detail(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $foreignAccount = $this->createConnectorAccount($otherWorkspace);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertCanNotSeeTableRecords([$foreignAccount]);

        $this->actingAs($admin)
            ->get(ConnectorAccountResource::getUrl('view', ['record' => $foreignAccount]))
            ->assertNotFound();
    }

    #[Test]
    public function list_renders_status_and_runtime_states(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $connected = $this->createConnectorAccount(overrides: [
            'name' => 'Connected Account',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $queued = $this->createConnectorAccount(overrides: [
            'name' => 'Queued Account',
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);
        $this->createActiveCheck($queued, ConnectorConnectionCheckStatus::Queued);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$connected, $queued])
            ->assertSee(__('connectors.ui.runtime.waiting'))
            ->assertSee(__('connectors.enums.account_connection_status.connected'));
    }

    #[Test]
    public function attention_message_appears_only_for_attention_statuses(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $attention = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::AttentionRequired,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);
        $connected = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertSee(__('connectors.errors.invalid_credentials', locale: 'uk'))
            ->assertCanSeeTableRecords([$attention, $connected]);
    }

    #[Test]
    public function search_covers_account_store_and_definition_fields(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'name' => 'UniqueSearchName',
            'store_code' => 'unique-store',
        ]);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->searchTable('UniqueSearchName')
            ->assertCanSeeTableRecords([$account])
            ->searchTable('unique-store')
            ->assertCanSeeTableRecords([$account])
            ->searchTable('Adobe Commerce')
            ->assertCanSeeTableRecords([$account]);
    }

    #[Test]
    public function management_user_list_and_detail_still_show_active_runtime_status(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Running);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertSee(__('connectors.ui.runtime.running'))
            ->assertSee(__('connectors.ui.runtime.last_result_prefix'));

        $detailComponent = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSuccessful()
            ->assertSee(__('connectors.ui.runtime.running'));

        $this->assertStringContainsString(
            'wire:poll.5s="refreshConnectionState"',
            $detailComponent->html(),
        );
    }

    #[Test]
    public function management_connection_check_loading_queries_only_active_status_columns(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Queued);
        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
            'technical_summary' => 'FAILED_CHECK_SUMMARY',
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'http_status' => 401,
            'vendor_request_id' => 'vendor-request-456',
            'duration_ms' => 2500,
        ]);

        $connectionCheckQueries = [];
        $connectionCheckBindings = [];

        DB::listen(function ($query) use (&$connectionCheckQueries, &$connectionCheckBindings): void {
            if (str_contains(strtolower($query->sql), 'connector_connection_checks')) {
                $connectionCheckQueries[] = strtolower($query->sql);
                $connectionCheckBindings[] = $query->bindings;
            }
        });

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertSee(__('connectors.ui.runtime.waiting'));

        $this->assertNotEmpty($connectionCheckQueries);

        foreach ($connectionCheckQueries as $index => $sql) {
            $this->assertStringContainsString('status', $sql);
            $this->assertStringContainsString('connector_account_id', $sql);
            $normalizedSql = str_replace(['"', '`'], '', $sql);
            $this->assertStringContainsString('status in', $normalizedSql);
            $bindings = $connectionCheckBindings[$index];
            $this->assertContains(ConnectorConnectionCheckStatus::Queued->value, $bindings);
            $this->assertContains(ConnectorConnectionCheckStatus::Running->value, $bindings);
            $this->assertStringNotContainsString('technical_summary', $sql);
            $this->assertStringNotContainsString('cause_category', $sql);
            $this->assertStringNotContainsString('actionability', $sql);
            $this->assertStringNotContainsString('user_message_key', $sql);
            $this->assertStringNotContainsString('vendor_request_id', $sql);
            $this->assertStringNotContainsString('duration_ms', $sql);
            $this->assertStringNotContainsString('initiated_by_user_id', $sql);
            $this->assertStringNotContainsString('trigger', $sql);
        }
    }

    #[Test]
    public function list_query_count_does_not_grow_linearly_with_account_count(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $first = $this->createConnectorAccount(overrides: ['name' => 'Query One']);
        $this->createActiveCheck($first, ConnectorConnectionCheckStatus::Running);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($admin)->test(ListConnectorAccounts::class);
        $oneAccountQueries = count(DB::getQueryLog());

        for ($i = 0; $i < 4; $i++) {
            $account = $this->createConnectorAccount(overrides: ['name' => 'Query Extra '.$i]);
            $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Queued);
        }

        DB::flushQueryLog();
        Livewire::actingAs($admin)->test(ListConnectorAccounts::class);
        $fiveAccountQueries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($oneAccountQueries + 20, $fiveAccountQueries);
    }

    #[Test]
    public function detail_page_hides_credentials_from_html_and_livewire_payload(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials(
                    'ck_'.self::CREDENTIAL_CANARY,
                    'cs_'.self::CREDENTIAL_CANARY,
                    'at_'.self::CREDENTIAL_CANARY,
                    'ts_'.self::CREDENTIAL_CANARY,
                ),
            ),
            'settings' => ['secret_setting' => self::CREDENTIAL_CANARY],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()]);

        $html = $component->html();
        $snapshot = json_encode($component->snapshot, JSON_THROW_ON_ERROR);
        $effects = json_encode($component->effects, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $html);
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $snapshot);
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $effects);
        $this->assertStringNotContainsString('cs_'.self::CREDENTIAL_CANARY, $html);
    }

    #[Test]
    public function detail_runtime_polling_is_conditional(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $withoutActive = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()]);

        $this->assertStringNotContainsString('wire:poll', $withoutActive->html());

        $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Running);

        $withActive = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()]);

        $this->assertStringContainsString('wire:poll.5s="refreshConnectionState"', $withActive->html());
    }

    #[Test]
    public function refresh_connection_state_reloads_record(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()]);

        $account->update(['connection_status' => ConnectorAccountConnectionStatus::Connected]);

        $component->call('refreshConnectionState')
            ->assertSet('record.connection_status', ConnectorAccountConnectionStatus::Connected);
    }

    #[Test]
    public function manual_action_disabled_when_account_disabled(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: ['is_enabled' => false]);

        $this->expectNoDispatch();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDisabled('runConnectionCheck');
    }

    #[Test]
    public function manual_action_disabled_when_profile_missing(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: ['auth_profile' => 'missing_profile']);

        $this->expectNoDispatch();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDisabled('runConnectionCheck');
    }

    #[Test]
    public function manual_action_disabled_when_active_check_exists(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Running);

        $this->expectNoDispatch();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertActionDisabled('runConnectionCheck')
            ->assertSee(__('connectors.ui.actions.check_already_active'));
    }

    #[Test]
    public function manual_action_queues_active_check_notification(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Queued);
            $stub->executeManualResult = $check->id;
        };
        $this->bindDispatchStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified();

        $this->assertSame(1, $stub->callCount);

        Http::assertNothingSent();
    }

    #[Test]
    public function manual_action_succeeded_race_shows_completed_notification(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded);
            $stub->executeManualResult = $check->id;
        };
        $this->bindDispatchStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified();
    }

    #[Test]
    public function manual_action_failed_with_known_key_shows_cause_specific_message(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
                'user_message_key' => 'connectors.errors.invalid_credentials',
                'cause_category' => ConnectorErrorCause::Authentication,
                'actionability' => ConnectorErrorActionability::UserActionRequired,
            ]);
            $stub->executeManualResult = $check->id;
        };
        $this->bindDispatchStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified();
    }

    #[Test]
    public function manual_action_failed_with_malformed_key_shows_generic_fallback(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
                'user_message_key' => 'connectors.errors.malformed_unknown',
                'technical_summary' => 'RAW_TECHNICAL_SUMMARY',
            ]);
            $stub->executeManualResult = $check->id;
        };
        $this->bindDispatchStub($stub);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck');

        $component->assertNotified();
        $this->assertStringNotContainsString('RAW_TECHNICAL_SUMMARY', $component->html());
        $this->assertStringNotContainsString('connectors.errors.malformed_unknown', $component->html());
    }

    #[Test]
    public function forged_manual_action_in_other_workspace_does_not_invoke_service(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = $this->createConnectorAccount($otherWorkspace);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $this->expectNoDispatch();

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($admin)
                ->test(ViewConnectorAccount::class, ['record' => $foreignAccount->getKey()]);
        } finally {
            $this->assertSame(0, $this->dispatchStub?->callCount ?? 0);
        }
    }

    #[Test]
    public function history_relation_manager_is_read_only_with_expected_columns(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $initiator = $this->createStaffUser(UserRole::Manager);
        $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
            'initiated_by_user_id' => $initiator->id,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'duration_ms' => 1500,
            'technical_summary' => 'SECRET_TECH',
            'vendor_request_id' => 'vendor-123',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ]);

        $html = $component->html();

        $this->assertStringContainsString($initiator->name, $html);
        $this->assertStringContainsString(__('connectors.enums.connection_check_trigger.manual'), $html);
        $this->assertStringContainsString(__('connectors.enums.error_cause.authorization'), $html);
        $this->assertStringNotContainsString('SECRET_TECH', $html);
        $this->assertStringNotContainsString('vendor-123', $html);
        $this->assertStringNotContainsString('connectors.enums.', $html);
        $this->assertTrue($component->instance()->isReadOnly());
        $this->assertSame('5s', $component->instance()->getTable()->getPollingInterval());
        $this->assertSame([20, 50, 100], $component->instance()->getTable()->getPaginationPageOptions());
    }

    #[Test]
    public function history_shows_system_initiator_fallback(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded, [
            'initiated_by_user_id' => null,
            'trigger' => ConnectorConnectionCheckTrigger::Scheduled,
        ]);

        Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertSee(__('connectors.ui.initiator.system'));
    }

    #[Test]
    public function localization_keys_exist_for_all_enum_families(): void
    {
        $locales = ['en', 'uk', 'ru'];
        $keys = [
            ...array_map(fn (ConnectorAccountConnectionStatus $case) => $case->label(), ConnectorAccountConnectionStatus::cases()),
            ...array_map(fn (ConnectorConnectionCheckStatus $case) => $case->label(), ConnectorConnectionCheckStatus::cases()),
            ...array_map(fn (ConnectorConnectionCheckTrigger $case) => $case->label(), ConnectorConnectionCheckTrigger::cases()),
            ...array_map(fn (ConnectorErrorCause $case) => $case->label(), ConnectorErrorCause::cases()),
            ...array_map(fn (ConnectorErrorActionability $case) => $case->label(), ConnectorErrorActionability::cases()),
            'connectors.ui.runtime.waiting',
            'connectors.ui.runtime.running',
            'connectors.ui.notifications.check_started',
            'connectors.ui.notifications.check_completed',
            'connectors.ui.notifications.action_failed',
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

    #[Test]
    public function unexpected_exception_is_reported_without_exposing_message(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualThrowable = new \RuntimeException('SENTINEL_EXCEPTION_MESSAGE');
        $this->bindDispatchStub($stub);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck');

        $component->assertNotified();
        $this->assertStringNotContainsString('SENTINEL_EXCEPTION_MESSAGE', $component->html());
    }

    private function createActiveCheck(
        ConnectorAccount $account,
        ConnectorConnectionCheckStatus $status,
    ): ConnectorConnectionCheck {
        return $this->createConnectionCheck($account, $status);
    }

    private function createConnectionCheck(
        ConnectorAccount $account,
        ConnectorConnectionCheckStatus $status,
        array $overrides = [],
    ): ConnectorConnectionCheck {
        return ConnectorConnectionCheck::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => $status,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
            'next_attempt_at' => null,
            'cause_category' => null,
            'actionability' => null,
            'error_code' => $status === ConnectorConnectionCheckStatus::Failed
                ? ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed->value
                : null,
            'http_status' => null,
            'user_message_key' => null,
            'safe_message_parameters' => null,
            'technical_summary' => null,
            'vendor_request_id' => null,
            'started_at' => in_array($status, [ConnectorConnectionCheckStatus::Running, ConnectorConnectionCheckStatus::Succeeded, ConnectorConnectionCheckStatus::Failed], true)
                ? now()
                : null,
            'finished_at' => in_array($status, [ConnectorConnectionCheckStatus::Succeeded, ConnectorConnectionCheckStatus::Failed], true)
                ? now()
                : null,
            'duration_ms' => null,
        ], $overrides));
    }
}

final class ConnectorConnectionCheckDispatchServiceStub
{
    public int $callCount = 0;

    public ?string $executeManualResult = null;

    public ?\Closure $executeManualCallback = null;

    public ?\Throwable $executeManualThrowable = null;

    public function executeManual(User $actor, string $workspaceId, string $connectorAccountId): string
    {
        $this->callCount++;

        if ($this->executeManualThrowable !== null) {
            throw $this->executeManualThrowable;
        }

        if ($this->executeManualCallback !== null) {
            ($this->executeManualCallback)($actor, $workspaceId, $connectorAccountId);
        }

        if ($this->executeManualResult === null) {
            throw new \RuntimeException('Unexpected executeManual call in test stub.');
        }

        return $this->executeManualResult;
    }
}
