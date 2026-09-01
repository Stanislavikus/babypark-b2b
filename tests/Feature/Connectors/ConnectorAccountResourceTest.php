<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorComponentReadiness;
use App\Enums\ConnectorConnectionCheckErrorCode;
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
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncReadinessResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequiredOperation;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Workspace\WorkspacePermissions;
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
        $this->bindAdobeExportSetupAuthorizationStub();
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

    private function bindReadinessResolverStub(AdobeSafeSyncComponentReadinessResolverStub $stub): void
    {
        $this->app->instance(AdobeSafeSyncComponentReadinessResolver::class, $stub);
    }

    private function bindAdobeExportSetupAuthorizationStub(bool $eligible = false): void
    {
        $this->app->instance(
            AdobeProductExportSetupAuthorizationService::class,
            new class($eligible)
            {
                public function __construct(
                    private readonly bool $eligible,
                ) {}

                public function isEligibleAdobeProductsExportSetupTarget(
                    User $actor,
                    Workspace $workspace,
                    string $connectorAccountId,
                ): bool {
                    return $this->eligible;
                }
            },
        );
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
    public function store_setup_block_reuses_manual_check_availability(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        $disabledAccount = $this->createConnectorAccount(overrides: ['is_enabled' => false]);
        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $disabledAccount->getKey()])
            ->assertActionDisabled('checkStoreSetup')
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'))
            ->assertSee(__('connectors.ui.disabled_reasons.account_disabled'));

        $activeAccount = $this->createConnectorAccount();
        $this->createActiveCheck($activeAccount, ConnectorConnectionCheckStatus::Running);
        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $activeAccount->getKey()])
            ->assertActionDisabled('checkStoreSetup')
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'))
            ->assertSee(__('connectors.ui.disabled_reasons.check_already_active'));
    }

    #[Test]
    public function unavailable_connection_check_profile_disables_readiness(): void
    {
        config(['connectors.profiles.adobe_commerce_paas_oauth1_integration.enabled' => false]);
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertActionDisabled('checkStoreSetup')
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'))
            ->assertSee(__('connectors.ui.disabled_reasons.profile_disabled'));
    }

    #[Test]
    public function actor_without_connector_management_cannot_execute_readiness(): void
    {
        $viewer = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($this->defaultWorkspace(), $viewer);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::success(),
            ConnectorComponentReadiness::Ready,
            true,
        );
        $this->bindReadinessResolverStub($stub);

        $component = Livewire::actingAs($viewer)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertDontSee(__('connectors.ui.readiness.store_setup'));

        $component
            ->assertDontSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertDontSee(__('connectors.ui.readiness.check'))
            ->call('mountAction', 'checkStoreSetup')
            ->assertSet('storeSetupState', 'NOT_CHECKED')
            ->assertSet('storeSetupBaselineMessage', null)
            ->assertDontSee(__('connectors.ui.readiness.store_setup'))
            ->assertDontSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertDontSee(__('connectors.ui.readiness.check'));

        $this->assertSame(0, $stub->callCount);
    }

    #[Test]
    public function store_setup_render_contract_matches_management_eligibility(): void
    {
        $account = $this->createConnectorAccount();

        $manager = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $managerStub = new AdobeSafeSyncComponentReadinessResolverStub;
        $managerStub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::success(),
            ConnectorComponentReadiness::Ready,
            true,
        );
        $this->bindReadinessResolverStub($managerStub);

        Livewire::actingAs($manager)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'));

        $this->assertSame(0, $managerStub->callCount);

        $viewer = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($this->defaultWorkspace(), $viewer);
        $viewerStub = new AdobeSafeSyncComponentReadinessResolverStub;
        $viewerStub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::success(),
            ConnectorComponentReadiness::Ready,
            true,
        );
        $this->bindReadinessResolverStub($viewerStub);

        Livewire::actingAs($viewer)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertDontSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertDontSee(__('connectors.ui.readiness.check'));

        $this->assertSame(0, $viewerStub->callCount);
    }

    #[Test]
    public function inline_store_setup_check_sets_ready_state_without_notification(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::success(),
            ConnectorComponentReadiness::Ready,
            true,
        );
        $this->bindReadinessResolverStub($stub);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'))
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'READY')
            ->assertSee(__('connectors.ui.readiness.ready.title'))
            ->assertSee(__('connectors.ui.readiness.ready.body'));

        $this->assertSame(1, $stub->callCount);
        $effects = json_encode($component->effects, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(__('connectors.ui.notifications.action_failed'), $effects);
        $this->assertStringNotContainsString(__('connectors.ui.notifications.check_failed'), $effects);
    }

    #[Test]
    public function inline_store_setup_ready_state_renders_optional_environment_details_when_available(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            connectionResult: ConnectorConnectionCheckResult::success(),
            componentReadiness: ConnectorComponentReadiness::Ready,
            baselineSucceeded: true,
            moduleVersion: '0.2.1',
            applicationVersion: '2.4.7-p1',
            phpVersion: '8.3.10',
        );
        $this->bindReadinessResolverStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSee('Magento 2.4.7-p1')
            ->assertSee('PHP 8.3.10')
            ->assertSee('Розширення 0.2.1');
    }

    #[Test]
    public function store_setup_developer_handoff_renders_canonical_requirements_from_manifest(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $manifestPath = dirname(__DIR__, 3).'/integrations/magento-safe-sync/composer.json';
        $raw = file_get_contents($manifestPath);
        $this->assertNotFalse($raw);

        $manifest = json_decode($raw, true);
        $this->assertIsArray($manifest);
        $require = $manifest['require'] ?? [];
        $this->assertIsArray($require);

        $expected = array_values(array_filter([
            $require['php'] ?? null,
            $require['magento/framework'] ?? null,
            $require['magento/module-catalog'] ?? null,
        ], fn ($value): bool => is_string($value) && $value !== ''));

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.readiness.developer.summary'))
            ->assertSee(__('connectors.ui.readiness.developer.requirements.title'));

        foreach ($expected as $constraint) {
            $component->assertSee($constraint);
        }
    }

    #[Test]
    public function store_setup_developer_handoff_packet_is_copy_safe_and_does_not_expose_credentials_or_base_url_or_settings(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'name' => 'Store Copy Packet',
            'base_url' => 'https://secret-shop.example.com',
            'store_code' => 'secret-store-code',
            'tenant_context' => 'secret-tenant',
            'settings' => ['secret_setting' => 'CANARY_PACKET_SETTING'],
            'credentials' => ['token' => 'CANARY_PACKET_CREDENTIAL'],
            'last_successful_check_at' => now(),
        ]);

        $iso = $account->last_successful_check_at?->toIso8601String();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.readiness.developer.packet.title'))
            ->assertSee('Store Copy Packet')
            ->assertSee($iso)
            ->assertDontSee('secret-shop.example.com')
            ->assertDontSee('CANARY_PACKET_SETTING')
            ->assertDontSee('CANARY_PACKET_CREDENTIAL');
    }

    #[Test]
    public function store_setup_developer_handoff_packet_updates_next_action_per_readiness_state(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $this->bindReadinessResolverStub($stub);

        $stub->result = new AdobeSafeSyncReadinessResult(
            connectionResult: ConnectorConnectionCheckResult::success(),
            componentReadiness: ConnectorComponentReadiness::SetupRequired,
            baselineSucceeded: true,
        );

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'SETUP_REQUIRED')
            ->assertSee(__('connectors.ui.readiness.developer.packet.next_action.setup_required'));

        $stub->result = new AdobeSafeSyncReadinessResult(
            connectionResult: ConnectorConnectionCheckResult::success(),
            componentReadiness: ConnectorComponentReadiness::UpdateRequired,
            baselineSucceeded: true,
        );

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'UPDATE_REQUIRED')
            ->assertSee(__('connectors.ui.readiness.developer.packet.next_action.update_required'));

        $stub->result = new AdobeSafeSyncReadinessResult(
            connectionResult: ConnectorConnectionCheckResult::success(),
            componentReadiness: ConnectorComponentReadiness::Ready,
            baselineSucceeded: true,
        );

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'READY')
            ->assertSee(__('connectors.ui.readiness.developer.packet.next_action.ready'));
    }

    #[Test]
    public function store_setup_developer_handoff_packet_tolerates_backward_handshake_without_versions(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            connectionResult: ConnectorConnectionCheckResult::success(),
            componentReadiness: ConnectorComponentReadiness::Ready,
            baselineSucceeded: true,
            moduleVersion: null,
            applicationVersion: null,
            phpVersion: null,
        );
        $this->bindReadinessResolverStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'READY')
            ->assertSee(__('connectors.ui.readiness.developer.packet.value.unknown', locale: 'uk'));
    }

    #[Test]
    public function inline_store_setup_baseline_failure_stays_inline_without_generic_notification(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
                401,
            ),
            null,
            false,
        );
        $this->bindReadinessResolverStub($stub);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'))
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'BASELINE_CONNECTION_FAILED')
            ->assertSee(__('connectors.ui.readiness.baseline_failure.title'))
            ->assertSee(__('connectors.errors.invalid_credentials'))
            ->assertSee(__('connectors.ui.readiness.baseline_failure.guidance'));

        $effects = json_encode($component->effects, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(__('connectors.ui.notifications.action_failed'), $effects);
        $this->assertStringNotContainsString(__('connectors.ui.notifications.check_failed'), $effects);
    }

    #[Test]
    public function baseline_success_with_probe_failure_preserves_connection_truth_and_maps_to_readiness_undetermined(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_successful_check_at' => now(),
        ]);

        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse,
                200,
            ),
            null,
            true,
        );
        $this->bindReadinessResolverStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'BASELINE_OK_READINESS_UNDETERMINED')
            ->assertSee(__('connectors.ui.readiness.readiness_undetermined.title'))
            ->assertSee(__('connectors.ui.readiness.readiness_undetermined.body'))
            ->assertDontSee(__('connectors.ui.readiness.baseline_failure.guidance'));
    }

    #[Test]
    public function baseline_success_with_temporary_probe_failure_maps_to_temporary_problem_state(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_successful_check_at' => now(),
        ]);

        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::transportFailure(
                ConnectorConnectionCheckErrorCode::TransportConnectionFailed,
            ),
            null,
            true,
        );
        $this->bindReadinessResolverStub($stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('checkStoreSetup')
            ->assertSet('storeSetupState', 'READINESS_TEMPORARY_PROBLEM')
            ->assertSee(__('connectors.ui.readiness.temporary_problem.title'))
            ->assertSee(__('connectors.ui.readiness.temporary_problem.body'))
            ->assertDontSee(__('connectors.ui.readiness.baseline_failure.guidance'));
    }

    #[Test]
    public function connector_account_overview_does_not_render_available_fields_summary_or_refresh_action(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertDontSee(__('connectors.ui.sections.available_fields'))
            ->assertDontSee(__('connectors.ui.actions.refresh_available_fields'));
    }

    #[Test]
    public function store_setup_check_reauthorizes_and_reloads_account_before_resolving(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);
        $account = $this->createConnectorAccount($workspace);
        $stub = new AdobeSafeSyncComponentReadinessResolverStub;
        $stub->result = new AdobeSafeSyncReadinessResult(
            ConnectorConnectionCheckResult::success(),
            ConnectorComponentReadiness::Ready,
            true,
        );
        $this->bindReadinessResolverStub($stub);

        $component = Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSee(__('connectors.ui.readiness.not_checked.body'))
            ->assertSee(__('connectors.ui.readiness.check'));

        $this->revokeAllWorkspaceRoles($membership);
        $actor->refresh();

        $this->actingAs($actor);

        $invokeStoreSetupCheck = new \ReflectionMethod($component->instance(), 'executeStoreSetupCheck');
        $invokeStoreSetupCheck->setAccessible(true);
        $invokeStoreSetupCheck->invoke($component->instance());

        $this->assertSame(0, $stub->callCount);
        $this->assertSame('NOT_CHECKED', $component->instance()->storeSetupState);
        $this->assertNull($component->instance()->storeSetupBaselineMessage);
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

final class AdobeSafeSyncComponentReadinessResolverStub
{
    public int $callCount = 0;

    public ?AdobeSafeSyncReadinessResult $result = null;

    public function resolve(
        string $workspaceId,
        string $connectorAccountId,
        AdobeSafeSyncRequiredOperation $operation,
    ): AdobeSafeSyncReadinessResult {
        $this->callCount++;

        if ($this->result === null) {
            throw new \RuntimeException('Unexpected resolve call in test stub.');
        }

        return $this->result;
    }
}
