<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountCapabilityPresentationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private const CREDENTIAL_CANARY = 'CANARY_MERCH_SECRET_4B2C1';

    private const SETTINGS_CANARY = 'CANARY_MERCH_SETTING_4B2C1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    private function discoveryActor(): User
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $user);

        return $user;
    }

    private function viewOnlyActor(): User
    {
        $user = $this->createStaffUser(UserRole::Manager);
        $this->grantConnectorView($this->defaultWorkspace(), $user);

        return $user;
    }

    #[Test]
    public function manager_with_manage_and_discovery_capabilities_prefers_connection_check_subheading(): void
    {
        config(['connectors.discovery.manual_trigger_enabled' => true]);

        $manager = $this->createStaffUserWithConnectorManage(UserRole::Manager);
        $account = $this->createConnectorAccount(overrides: [
            'is_enabled' => true,
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Running);

        $detailComponent = Livewire::actingAs($manager)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $expectedReason = __('connectors.ui.disabled_reasons.check_already_active');
        $discoveryReason = __('connectors.ui.disabled_reasons.discovery_already_active');

        $this->assertSame($expectedReason, $detailComponent->instance()->getSubheading());
        $this->assertNotSame($discoveryReason, $detailComponent->instance()->getSubheading());
    }

    #[Test]
    public function discovery_only_actor_shows_discovery_disabled_subheading(): void
    {
        config(['connectors.discovery.manual_trigger_enabled' => true]);

        $actor = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'is_enabled' => false,
            'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
        ]);

        $detailComponent = Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $this->assertSame(
            __('connectors.ui.disabled_reasons.account_disabled'),
            $detailComponent->instance()->getSubheading(),
        );
    }

    #[Test]
    public function view_only_actor_has_no_management_or_discovery_subheading(): void
    {
        config(['connectors.discovery.manual_trigger_enabled' => true]);

        $viewer = $this->viewOnlyActor();
        $account = $this->createConnectorAccount(overrides: [
            'is_enabled' => false,
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $detailComponent = Livewire::actingAs($viewer)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $this->assertNull($detailComponent->instance()->getSubheading());
    }

    #[Test]
    public function view_only_actor_list_and_detail_hide_sensitive_fields_without_management_surfaces(): void
    {
        $viewer = $this->viewOnlyActor();
        $account = $this->createConnectorAccount(overrides: [
            'base_url' => 'https://secret-shop.example.com',
            'store_code' => 'secret-store',
            'tenant_context' => 'secret-tenant',
            'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials(
                    'ck_'.self::CREDENTIAL_CANARY,
                    'cs_'.self::CREDENTIAL_CANARY,
                    'at_'.self::CREDENTIAL_CANARY,
                    'ts_'.self::CREDENTIAL_CANARY,
                ),
            ),
            'settings' => ['secret_setting' => self::SETTINGS_CANARY],
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Running, [
            'technical_summary' => 'SECRET_RUNTIME_SUMMARY',
        ]);

        $listComponent = Livewire::actingAs($viewer)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account]);

        $detailComponent = Livewire::actingAs($viewer)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSuccessful();

        $canaries = [
            self::CREDENTIAL_CANARY,
            self::SETTINGS_CANARY,
            'secret-shop.example.com',
            'SECRET_RUNTIME_SUMMARY',
            'runConnectionCheck',
            'runDiscovery',
            'connectionChecks',
            'ConnectionChecksRelationManager',
        ];

        $this->assertNoCanariesInSurface(
            $canaries,
            $listComponent->html(),
            json_encode($listComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($listComponent->effects, JSON_THROW_ON_ERROR),
            $detailComponent->html(),
            json_encode($detailComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($detailComponent->effects, JSON_THROW_ON_ERROR),
        );

        $headerActions = (new \ReflectionMethod(ViewConnectorAccount::class, 'getHeaderActions'))
            ->invoke($detailComponent->instance());
        $relationManagers = (new \ReflectionMethod(ViewConnectorAccount::class, 'getAllRelationManagers'))
            ->invoke($detailComponent->instance());

        $this->assertSame([], $headerActions);
        $this->assertSame([], $relationManagers);
        $this->assertNull($detailComponent->instance()->getSubheading());

        $connectionCheckQueries = $this->captureConnectionCheckQueriesDuring(function () use ($viewer, $account): void {
            Livewire::actingAs($viewer)
                ->test(ListConnectorAccounts::class)
                ->assertCanSeeTableRecords([$account]);
        });

        $this->assertSame([], $connectionCheckQueries);
    }

    #[Test]
    public function discovery_actor_can_reach_list_and_view_enabled_account(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount();

        $this->assertTrue($merchandiser->can('viewAny', ConnectorAccount::class));
        $this->assertTrue($merchandiser->can('view', $account));

        Livewire::actingAs($merchandiser)
            ->test(ListConnectorAccounts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$account]);

        Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();
    }

    #[Test]
    public function merchandiser_list_and_detail_hide_sensitive_fields_from_html_and_livewire_payload(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'base_url' => 'https://secret-shop.example.com',
            'store_code' => 'secret-store',
            'tenant_context' => 'secret-tenant',
            'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials(
                    'ck_'.self::CREDENTIAL_CANARY,
                    'cs_'.self::CREDENTIAL_CANARY,
                    'at_'.self::CREDENTIAL_CANARY,
                    'ts_'.self::CREDENTIAL_CANARY,
                ),
            ),
            'settings' => ['secret_setting' => self::SETTINGS_CANARY],
        ]);

        $listComponent = Livewire::actingAs($merchandiser)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account]);

        $detailComponent = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()]);

        $canaries = [
            self::CREDENTIAL_CANARY,
            'cs_'.self::CREDENTIAL_CANARY,
            self::SETTINGS_CANARY,
            'secret-shop.example.com',
            'secret-store',
            'secret-tenant',
            'adobe_commerce_paas_oauth1_integration',
        ];

        $this->assertNoCanariesInSurface(
            $canaries,
            $listComponent->html(),
            json_encode($listComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($listComponent->effects, JSON_THROW_ON_ERROR),
            $detailComponent->html(),
            json_encode($detailComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($detailComponent->effects, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function merchandiser_safe_query_limits_selected_columns(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount();

        $presentation = app(ConnectorAccountCapabilityPresentation::class);
        $query = $presentation->applyRestrictedQuery(ConnectorAccount::query(), $merchandiser, $this->defaultWorkspace());

        $record = $query->whereKey($account->id)->firstOrFail();

        foreach ([
            'credentials',
            'settings',
            'base_url',
            'store_code',
            'tenant_context',
            'auth_profile',
        ] as $sensitiveColumn) {
            $this->assertFalse(
                $record->offsetExists($sensitiveColumn),
                "Sensitive column [{$sensitiveColumn}] must not be selected for merchandiser queries.",
            );
        }

        $this->assertTrue($record->offsetExists('name'));
        $this->assertTrue($record->offsetExists('connection_status'));
    }

    #[Test]
    public function merchandiser_sanitize_record_hides_sensitive_attributes(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'settings' => ['secret' => self::SETTINGS_CANARY],
        ])->fresh();

        $sanitized = app(ConnectorAccountCapabilityPresentation::class)
            ->sanitizeRecord($account, $merchandiser, $this->defaultWorkspace());

        foreach (ConnectorAccountCapabilityPresentation::hiddenAttributes() as $attribute) {
            $this->assertArrayNotHasKey($attribute, $sanitized->toArray(), "Attribute [{$attribute}] must be hidden.");
        }
    }

    #[Test]
    public function merchandiser_detail_hides_connection_check_management_and_history(): void
    {
        $merchandiser = $this->discoveryActor();
        $initiator = $this->createStaffUser(UserRole::Manager);
        $account = $this->createConnectorAccount(overrides: [
            'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
            'is_enabled' => false,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
            'initiated_by_user_id' => $initiator->id,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'duration_ms' => 1500,
            'technical_summary' => 'SECRET_TECH_SUMMARY',
        ]);

        $detailComponent = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()]);

        $detailComponent->assertSuccessful();
        $detailComponent->assertDontSee(__('connectors.ui.relation.connection_checks'));

        $forbidden = [
            $initiator->email,
            $initiator->name,
            __('connectors.enums.connection_check_trigger.manual'),
            __('connectors.enums.error_cause.authorization'),
            __('connectors.enums.error_actionability.user_action_required'),
            __('connectors.ui.disabled_reasons.account_disabled'),
            'adobe_commerce_paas_oauth1_integration',
            'SECRET_TECH_SUMMARY',
            'connectionChecks',
            'ConnectionChecksRelationManager',
            'runConnectionCheck',
        ];

        $this->assertNoCanariesInSurface(
            $forbidden,
            $detailComponent->html(),
            json_encode($detailComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($detailComponent->effects, JSON_THROW_ON_ERROR),
        );

        $headerActions = (new \ReflectionMethod(ViewConnectorAccount::class, 'getHeaderActions'))
            ->invoke($detailComponent->instance());
        $relationManagers = (new \ReflectionMethod(ViewConnectorAccount::class, 'getAllRelationManagers'))
            ->invoke($detailComponent->instance());

        $this->assertSame([], $headerActions);
        $this->assertSame([], $relationManagers);
        $this->assertNull($detailComponent->instance()->getSubheading());
    }

    #[Test]
    public function merchandiser_list_rendering_executes_no_connection_check_queries(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Running, [
            'technical_summary' => 'SECRET_RUNTIME_SUMMARY',
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'http_status' => 401,
            'vendor_request_id' => 'vendor-request-123',
        ]);

        $connectionCheckQueries = $this->captureConnectionCheckQueriesDuring(function () use ($merchandiser, $account): void {
            Livewire::actingAs($merchandiser)
                ->test(ListConnectorAccounts::class)
                ->assertCanSeeTableRecords([$account]);
        });

        $this->assertSame([], $connectionCheckQueries);
    }

    #[Test]
    public function merchandiser_detail_rendering_executes_no_connection_check_queries(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Queued, [
            'technical_summary' => 'SECRET_RUNTIME_SUMMARY',
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'http_status' => 401,
            'vendor_request_id' => 'vendor-request-123',
        ]);

        $connectionCheckQueries = $this->captureConnectionCheckQueriesDuring(function () use ($merchandiser, $account): void {
            Livewire::actingAs($merchandiser)
                ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
                ->assertSuccessful();
        });

        $this->assertSame([], $connectionCheckQueries);
    }

    #[Test]
    public function merchandiser_list_and_detail_show_only_stable_connection_status_without_runtime_overlay(): void
    {
        $merchandiser = $this->discoveryActor();
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Running, [
            'technical_summary' => 'SECRET_RUNTIME_SUMMARY',
            'cause_category' => ConnectorErrorCause::Authorization,
            'actionability' => ConnectorErrorActionability::UserActionRequired,
            'user_message_key' => 'connectors.errors.insufficient_permissions',
            'http_status' => 401,
            'vendor_request_id' => 'vendor-request-123',
            'duration_ms' => 1500,
        ]);

        $listComponent = Livewire::actingAs($merchandiser)
            ->test(ListConnectorAccounts::class)
            ->assertCanSeeTableRecords([$account]);

        $detailComponent = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertSuccessful();

        $forbidden = [
            __('connectors.ui.runtime.running'),
            __('connectors.ui.runtime.waiting'),
            __('connectors.ui.runtime.last_result_prefix'),
            'SECRET_RUNTIME_SUMMARY',
            'vendor-request-123',
            'connectionChecks',
            'technical_summary',
            'cause_category',
            'actionability',
            'vendor_request_id',
            'duration_ms',
            'refreshConnectionState',
        ];

        $this->assertNoCanariesInSurface(
            $forbidden,
            $listComponent->html(),
            json_encode($listComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($listComponent->effects, JSON_THROW_ON_ERROR),
            $detailComponent->html(),
            json_encode($detailComponent->snapshot, JSON_THROW_ON_ERROR),
            json_encode($detailComponent->effects, JSON_THROW_ON_ERROR),
        );

        $this->assertStringContainsString(
            e(__('connectors.enums.account_connection_status.connected')),
            $detailComponent->html(),
        );
        $this->assertStringNotContainsString(
            'wire:poll.5s="refreshConnectionState"',
            $detailComponent->html(),
        );
    }

    /**
     * @return list<string>
     */
    private function captureConnectionCheckQueriesDuring(callable $callback): array
    {
        $connectionCheckQueries = [];

        DB::listen(function ($query) use (&$connectionCheckQueries): void {
            if (str_contains(strtolower($query->sql), 'connector_connection_checks')) {
                $connectionCheckQueries[] = $query->sql;
            }
        });

        $callback();

        return $connectionCheckQueries;
    }

    /**
     * @param  list<string>  $canaries
     */
    private function assertNoCanariesInSurface(array $canaries, string ...$surfaces): void
    {
        foreach ($canaries as $canary) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($canary, $surface, "Canary [{$canary}] leaked into merchandiser UI surface.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
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
            'error_code' => null,
            'http_status' => null,
            'user_message_key' => null,
            'safe_message_parameters' => null,
            'technical_summary' => null,
            'vendor_request_id' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => null,
        ], $overrides));
    }
}
