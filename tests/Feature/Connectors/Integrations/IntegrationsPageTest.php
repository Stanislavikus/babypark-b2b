<?php

namespace Tests\Feature\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\UserRole;
use App\Filament\Pages\Integrations\ConnectPlatformIntegration;
use App\Filament\Pages\Integrations\Integrations;
use App\Filament\Pages\Integrations\ListPlatformConnections;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorDefinitionResource;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDefinition;
use App\Support\Platform\PlatformAdminAuthorization;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('uk');
    }

    #[Test]
    public function merchandiser_sees_platform_cards_and_ask_admin_instead_of_connect(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorView($this->defaultWorkspace(), $user);

        $component = Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Adobe Commerce')
            ->assertSee('Ще не підключено')
            ->assertSee('Для підключення зверніться до адміністратора')
            ->assertDontSee('Підключити');

        // Forbidden merchant vocabulary (§13) in rendered card copy — not Livewire internals
        // such as wire:snapshot attributes, which are out of scope for vocabulary canaries.
        $renderedCards = collect($component->get('cards'))
            ->map(fn (array $card): string => implode(' ', [
                $card['platform_name'],
                $card['status_label'],
                $card['secondary_line'],
                $card['runtime_overlay_label'] ?? '',
                $card['primary_action_label'] ?? '',
                $card['secondary_action_hint'] ?? '',
            ]))
            ->implode(' ');

        foreach ([
            'знімок',
            'snapshot',
            'discovery run',
            'джерело схеми',
            'endpoint path',
            'auth profile',
            'canonical hash',
            'schema source',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($renderedCards));
        }
    }

    #[Test]
    public function admin_sees_connect_for_setup_capable_platform(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Adobe Commerce')
            ->assertSee('Підключити')
            ->assertDontSee('Shopify')
            ->assertDontSee('Google Merchant Center')
            ->assertDontSee('Для підключення зверніться до адміністратора')
            ->assertDontSee('незабаром буде доступне')
            ->assertDontSee('coming soon');
    }

    #[Test]
    public function active_platform_without_account_setup_is_absent_until_an_account_exists(): void
    {
        $shopify = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertDontSee('Shopify');

        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $shopify->id,
            'name' => 'Shopify Main',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Shopify')
            ->assertSee('Працює')
            ->assertDontSee('незабаром буде доступне');
    }

    #[Test]
    public function single_account_card_opens_overview_and_shows_connected_vocabulary(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->defaultWorkspace(), [
            'name' => 'Adobe Commerce',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => now(),
            'last_successful_check_at' => now(),
            'last_discovery_at' => now()->subDay(),
            'last_successful_discovery_at' => now()->subDay(),
        ]);

        $html = Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Працює')
            ->assertSee('Відкрити')
            ->assertDontSee('Отримання полів')
            ->assertDontSee('знімок')
            ->html();

        $this->assertStringContainsString(
            ConnectorAccountResource::getUrl('view', ['record' => $account]),
            $html,
        );
        $this->assertStringNotContainsString('last_discovery', $html);
        $this->assertStringNotContainsString((string) $account->last_discovery_at, $html);
    }

    #[Test]
    public function mixed_healthy_and_disabled_accounts_do_not_render_disabled_platform_status(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $definitionId = $this->adobeConnectorDefinition()->id;

        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store A',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'is_enabled' => true,
        ]);
        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store B',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'is_enabled' => true,
        ]);
        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store C',
            'connection_status' => ConnectorAccountConnectionStatus::Disabled,
            'is_enabled' => false,
        ]);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Працює')
            ->assertSee('Відкрити')
            ->assertDontSeeHtml('>Вимкнено<');
    }

    #[Test]
    public function multi_account_open_goes_to_platform_connection_list(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $definitionId = $this->adobeConnectorDefinition()->id;

        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store A',
            'connection_status' => ConnectorAccountConnectionStatus::AttentionRequired,
        ]);
        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store B',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        $listUrl = ListPlatformConnections::getUrl(['platform' => 'adobe_commerce']);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Потребує уваги')
            ->assertSee($listUrl, false);
    }

    #[Test]
    public function single_account_active_check_reuses_ui_state_runtime_overlay(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($this->defaultWorkspace(), [
            'name' => 'Adobe Commerce',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => now()->subHour(),
        ]);

        ConnectorConnectionCheck::factory()->create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'connector_account_id' => $account->id,
            'status' => ConnectorConnectionCheckStatus::Running,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'started_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Працює')
            ->assertSee(__('connectors.ui.runtime.running'));
    }

    #[Test]
    public function draft_platforms_never_appear_and_deprecated_without_account_hidden(): void
    {
        ConnectorDefinition::query()->create([
            'code' => 'legacy_hidden',
            'name' => 'Legacy Hidden',
            'direction' => ConnectorDirection::Both,
            'status' => ConnectorDefinitionStatus::Deprecated,
        ]);

        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertDontSee('BigCommerce')
            ->assertDontSee('Legacy Hidden')
            ->assertDontSee('CSV');
    }

    #[Test]
    public function integrations_access_does_not_widen_platform_admin_gate(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorView($this->defaultWorkspace(), $user);

        $this->assertFalse(PlatformAdminAuthorization::canManage($user));

        $this->actingAs($user);
        $this->assertTrue(Integrations::canAccess());
    }

    #[Test]
    public function integrations_is_registered_as_ungrouped_merchant_navigation(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $this->actingAs($user);

        $this->assertTrue(Integrations::shouldRegisterNavigation());
        $this->assertNull(Integrations::getNavigationGroup());
        $this->assertSame(__('connectors.ui.integrations.navigation_label'), Integrations::getNavigationLabel());
    }

    #[Test]
    public function integrations_navigation_group_translation_key_is_removed(): void
    {
        foreach (['uk', 'ru', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->assertFalse(Lang::has('connectors.ui.integrations.navigation_group'));
        }
    }

    #[Test]
    public function connector_definition_resource_navigation_is_unchanged(): void
    {
        $this->assertSame('Платформи та джерела', ConnectorDefinitionResource::getNavigationLabel());
        $this->assertSame('Модель даних і коннектори', ConnectorDefinitionResource::getNavigationGroup());
    }

    #[Test]
    public function connector_setup_profile_resolver_hardcoded_map_is_removed(): void
    {
        $this->assertFalse(class_exists('App\\Support\\Connectors\\Integrations\\ConnectorSetupProfileResolver'));
        $this->assertFileDoesNotExist(app_path('Support/Connectors/Integrations/ConnectorSetupProfileResolver.php'));
    }

    #[Test]
    public function merchandiser_cannot_access_connect_route(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);

        $this->actingAs($user);
        $this->assertFalse(ConnectPlatformIntegration::canAccess());
    }

    #[Test]
    public function multi_account_list_shows_per_row_overlay_not_aggregate_runtime_on_landing(): void
    {
        $user = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $definitionId = $this->adobeConnectorDefinition()->id;

        $a = $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store A',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definitionId,
            'name' => 'Store B',
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);

        ConnectorConnectionCheck::factory()->create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'connector_account_id' => $a->id,
            'status' => ConnectorConnectionCheckStatus::Running,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'started_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Integrations::class)
            ->assertSuccessful()
            ->assertSee('Працює')
            ->assertDontSee(__('connectors.ui.runtime.running'));

        Livewire::actingAs($user)
            ->test(ListPlatformConnections::class, ['platform' => 'adobe_commerce'])
            ->assertSuccessful()
            ->assertSee('Store A')
            ->assertSee('Store B')
            ->assertSee(__('connectors.ui.runtime.running'))
            ->assertSee('Підключити ще');
    }
}
