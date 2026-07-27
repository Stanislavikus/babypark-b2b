<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorCause;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\ConnectionChecksRelationManager;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountResourceReviewTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private const CREDENTIAL_CANARY = 'CANARY_UI_SECRET_4B2A3_REVIEW';

    private const TECHNICAL_CANARY = 'CANARY_TECH_SUMMARY_4B2A3';

    private const VENDOR_ID_CANARY = 'CANARY_VENDOR_REQ_4B2A3';

    private const ERROR_CODE_CANARY = 'CANARY_ERROR_CODE_4B2A3';

    private ?ConnectorConnectionCheckDispatchServiceStub $dispatchStub = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        $this->dispatchStub = new ConnectorConnectionCheckDispatchServiceStub;
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $this->dispatchStub);
    }

    #[Test]
    public function denied_user_does_not_see_connector_accounts_in_navigation(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $admin = $this->createStaffUser(UserRole::Admin);

        App::setLocale('uk');
        $label = __('connectors.ui.resource.navigation_label', locale: 'uk');

        $this->assertFalse($merchandiser->can('viewAny', ConnectorAccount::class));

        $this->actingAs($admin)
            ->get(ConnectorAccountResource::getUrl('index'))
            ->assertSee($label);

        $this->actingAs($merchandiser)
            ->get(ConnectorAccountResource::getUrl('index'))
            ->assertForbidden();
    }

    #[Test]
    public function authorized_user_sees_connector_accounts_navigation_label(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);

        App::setLocale('uk');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertSee(__('connectors.ui.resource.navigation_label', locale: 'uk'));
    }

    #[Test]
    public function search_finds_tenant_context_and_definition_code(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $byTenant = $this->createConnectorAccount(overrides: [
            'name' => 'Tenant Search Account',
            'store_code' => 'default',
            'tenant_context' => 'tenant-unique-ctx',
        ]);
        $byCode = $this->createConnectorAccount(overrides: [
            'name' => 'Code Search Account',
            'store_code' => 'default',
            'tenant_context' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->searchTable('tenant-unique-ctx')
            ->assertCanSeeTableRecords([$byTenant])
            ->assertCanNotSeeTableRecords([$byCode])
            ->searchTable('adobe_commerce')
            ->assertCanSeeTableRecords([$byTenant, $byCode]);
    }

    #[Test]
    public function attention_message_is_row_scoped_for_attention_statuses_only(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $message = __('connectors.errors.invalid_credentials', locale: 'uk');

        $attentionRequired = $this->createConnectorAccount(overrides: [
            'name' => 'Attention Required Row',
            'connection_status' => ConnectorAccountConnectionStatus::AttentionRequired,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);
        $temporarilyUnavailable = $this->createConnectorAccount(overrides: [
            'name' => 'Temporarily Unavailable Row',
            'connection_status' => ConnectorAccountConnectionStatus::TemporarilyUnavailable,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);
        $connected = $this->createConnectorAccount(overrides: [
            'name' => 'Connected Row',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);
        $untested = $this->createConnectorAccount(overrides: [
            'name' => 'Untested Row',
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);
        $disabled = $this->createConnectorAccount(overrides: [
            'name' => 'Disabled Row',
            'connection_status' => ConnectorAccountConnectionStatus::Disabled,
            'is_enabled' => false,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
        ]);

        Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->assertTableColumnFormattedStateSet('last_error_message_key', $message, $attentionRequired)
            ->assertTableColumnFormattedStateSet('last_error_message_key', $message, $temporarilyUnavailable)
            ->assertTableColumnFormattedStateNotSet('last_error_message_key', $message, $connected)
            ->assertTableColumnFormattedStateNotSet('last_error_message_key', $message, $untested)
            ->assertTableColumnFormattedStateNotSet('last_error_message_key', $message, $disabled);
    }

    #[Test]
    #[DataProvider('disabledManualActionProvider')]
    public function disabled_manual_action_shows_reason_and_does_not_dispatch(
        array $accountOverrides,
        string $reasonKey,
        ?callable $arrange = null,
    ): void {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: $accountOverrides);

        if ($arrange !== null) {
            $arrange($this, $account);
        }

        $this->dispatchStub = new ConnectorConnectionCheckDispatchServiceStub;
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $this->dispatchStub);

        App::setLocale('uk');

        $reason = __($reasonKey, locale: 'uk');

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()])
            ->assertActionDisabled('runConnectionCheck')
            ->assertSee($reason);

        $component->callAction('runConnectionCheck');
        $this->assertSame(0, $this->dispatchStub->callCount);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2?: callable|null}>
     */
    public static function disabledManualActionProvider(): array
    {
        return [
            'account disabled' => [
                ['is_enabled' => false],
                'connectors.ui.disabled_reasons.account_disabled',
            ],
            'profile missing' => [
                ['auth_profile' => 'missing_profile'],
                'connectors.ui.disabled_reasons.profile_not_found',
            ],
            'profile disabled' => [
                ['auth_profile' => 'disabled_profile_review'],
                'connectors.ui.disabled_reasons.profile_disabled',
                function (self $test, ConnectorAccount $account): void {
                    $test->registerReviewProfile('disabled_profile_review', enabled: false);
                    $account->update(['auth_profile' => 'disabled_profile_review']);
                },
            ],
            'capability unsupported' => [
                ['auth_profile' => 'unsupported_profile_review'],
                'connectors.ui.disabled_reasons.capability_unsupported',
                function (self $test, ConnectorAccount $account): void {
                    $test->registerReviewProfile('unsupported_profile_review', capabilities: ['schema_discovery']);
                    $account->update(['auth_profile' => 'unsupported_profile_review']);
                },
            ],
            'active check already running' => [
                [],
                'connectors.ui.disabled_reasons.check_already_active',
                function (self $test, ConnectorAccount $account): void {
                    $test->createConnectionCheck($account, ConnectorConnectionCheckStatus::Running);
                },
            ],
        ];
    }

    #[Test]
    public function manual_action_notifies_exact_started_copy_for_active_check(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Queued);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(__('connectors.ui.notifications.check_started'));
    }

    #[Test]
    public function manual_action_notifies_exact_completed_copy_for_succeeded_race(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $account->update(['connection_status' => ConnectorAccountConnectionStatus::Connected]);
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(__('connectors.ui.notifications.check_completed'))
            ->assertSet('record.connection_status', ConnectorAccountConnectionStatus::Connected);
    }

    #[Test]
    public function manual_action_notifies_exact_cause_specific_failed_copy(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $expectedBody = __('connectors.errors.invalid_credentials');
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
                'user_message_key' => 'connectors.errors.invalid_credentials',
            ]);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('connectors.ui.notifications.check_failed'))
                    ->body($expectedBody),
            );
    }

    #[Test]
    public function manual_action_notifies_exact_generic_failed_copy_for_lifecycle_failure(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $expectedBody = __('connectors.errors.connection_check_failed');
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
                'user_message_key' => 'connectors.errors.malformed_unknown',
                'error_code' => ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed->value,
                'technical_summary' => self::TECHNICAL_CANARY,
            ]);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('connectors.ui.notifications.check_failed'))
                    ->body($expectedBody),
            );
    }

    #[Test]
    public function relation_manager_refreshes_immediately_on_event_without_waiting_for_poll(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();

        $component = Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertCountTableRecords(0);

        $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded);

        $component
            ->dispatch('refreshRelationManagers')
            ->assertCanSeeTableRecords([$check]);
    }

    #[Test]
    public function manual_action_refreshes_history_immediately_for_active_check(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Queued);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertSee(__('connectors.enums.connection_check_status.queued'))
            ->assertSee(__('connectors.ui.runtime.waiting'));
    }

    #[Test]
    public function manual_action_refreshes_history_and_projection_for_succeeded_race(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $account->update(['connection_status' => ConnectorAccountConnectionStatus::Connected]);
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertSet('record.connection_status', ConnectorAccountConnectionStatus::Connected);

        Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account->fresh(),
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertCanSeeTableRecords([
                ConnectorConnectionCheck::query()->where('connector_account_id', $account->id)->first(),
            ]);
    }

    #[Test]
    public function manual_action_refreshes_history_for_failed_race(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualCallback = function () use ($account, $stub): void {
            $check = $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Failed, [
                'user_message_key' => 'connectors.errors.insufficient_permissions',
                'cause_category' => ConnectorErrorCause::Authorization,
            ]);
            $stub->executeManualResult = $check->id;
        };
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('connectors.ui.notifications.check_failed'))
                    ->body(__('connectors.errors.insufficient_permissions')),
            );

        Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account->fresh(),
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->assertSee(__('connectors.enums.connection_check_status.failed'));
    }

    #[Test]
    public function detail_page_hides_secrets_from_html_and_livewire_snapshot(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
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
        $this->createActiveCheck($account, ConnectorConnectionCheckStatus::Running, [
            'technical_summary' => self::TECHNICAL_CANARY,
            'error_code' => self::ERROR_CODE_CANARY,
            'vendor_request_id' => self::VENDOR_ID_CANARY,
            'user_message_key' => 'connectors.errors.invalid_credentials',
            'safe_message_parameters' => ['note' => 'SAFE_PARAM_ONLY'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->fresh()->getKey()]);

        $canaries = [
            self::CREDENTIAL_CANARY,
            'cs_'.self::CREDENTIAL_CANARY,
            self::TECHNICAL_CANARY,
            self::ERROR_CODE_CANARY,
            self::VENDOR_ID_CANARY,
        ];

        $this->assertNoCanariesInSurface(
            $canaries,
            $component->html(),
            json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            json_encode($component->effects, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function unexpected_exception_path_does_not_log_connector_secrets(): void
    {
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials(self::CREDENTIAL_CANARY, self::CREDENTIAL_CANARY, self::CREDENTIAL_CANARY, self::CREDENTIAL_CANARY),
            ),
        ]);

        $stub = new ConnectorConnectionCheckDispatchServiceStub;
        $stub->executeManualThrowable = new \RuntimeException('SENTINEL_EXCEPTION_MESSAGE');
        $this->app->instance(ConnectorConnectionCheckDispatchService::class, $stub);

        Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->callAction('runConnectionCheck')
            ->assertNotified(__('connectors.ui.notifications.action_failed'));

        $loggedPayload = collect($logged)
            ->map(static fn (MessageLogged $event): string => json_encode([
                'message' => $event->message,
                'context' => $event->context,
            ], JSON_THROW_ON_ERROR))
            ->implode('');

        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $loggedPayload);
        $this->assertStringNotContainsString('SENTINEL_EXCEPTION_MESSAGE', $loggedPayload);
    }

    #[Test]
    #[DataProvider('localePresentationProvider')]
    public function localized_labels_dates_and_durations_render_without_raw_keys(string $locale, array $expected, array $forbidden): void
    {
        App::setLocale($locale);

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount(overrides: [
            'last_checked_at' => '2026-07-27 14:30:00',
        ]);
        $this->createConnectionCheck($account, ConnectorConnectionCheckStatus::Succeeded, [
            'duration_ms' => 1500,
            'trigger' => ConnectorConnectionCheckTrigger::BeforeDiscovery,
        ]);

        $listHtml = Livewire::actingAs($admin)
            ->test(ListConnectorAccounts::class)
            ->html();

        $historyHtml = Livewire::actingAs($admin)
            ->test(ConnectionChecksRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => ViewConnectorAccount::class,
            ])
            ->html();

        $combined = $listHtml.$historyHtml;
        $navigationLabel = ConnectorAccountResource::getNavigationLabel();
        $this->assertSame(__('connectors.ui.resource.navigation_label', locale: $locale), $navigationLabel);

        foreach ($expected as $label) {
            $this->assertStringContainsString($label, $combined, "Expected [{$label}] for locale [{$locale}]");
        }

        foreach ($forbidden as $label) {
            $this->assertStringNotContainsString($label, $combined, "Forbidden [{$label}] for locale [{$locale}]");
        }

        $this->assertStringNotContainsString('connectors.ui.', $combined);
        $this->assertStringNotContainsString('connectors.enums.', $combined);
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function localePresentationProvider(): array
    {
        return [
            'uk' => [
                'uk',
                [
                    'Спосіб запуску',
                    'Перед отриманням полів',
                ],
                ['Trigger', 'Before discovery', 'Тригер'],
            ],
            'en' => [
                'en',
                [
                    'Launch method',
                    'Before field discovery',
                ],
                ['Тригер', 'Перед discovery', 'Тригер'],
            ],
            'ru' => [
                'ru',
                [
                    'Способ запуска',
                    'Перед получением полей',
                ],
                ['Trigger', 'Before discovery', 'Тригер'],
            ],
        ];
    }

    private function registerReviewProfile(string $code, bool $enabled = true, array $capabilities = ['connection_check']): void
    {
        config([
            'connectors.profiles' => array_merge(config('connectors.profiles'), [
                $code => [
                    'enabled' => $enabled,
                    'adapter' => AdobePaaSConnectorAdapter::class,
                    'account_schema' => AdobePaaSAccountSchema::class,
                    'capabilities' => $capabilities,
                ],
            ]),
        ]);

        $this->app->forgetInstance(ConnectorProfileRegistry::class);
    }

    private function assertNoCanariesInSurface(array $canaries, string ...$surfaces): void
    {
        foreach ($canaries as $canary) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($canary, $surface, "Canary [{$canary}] leaked into UI surface.");
            }
        }
    }

    private function createActiveCheck(
        ConnectorAccount $account,
        ConnectorConnectionCheckStatus $status,
        array $overrides = [],
    ): ConnectorConnectionCheck {
        return $this->createConnectionCheck($account, $status, $overrides);
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
            'error_code' => null,
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
