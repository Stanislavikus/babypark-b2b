<?php

namespace Tests\Feature\Connectors;

use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountMerchandiserPresentationTest extends TestCase
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    #[Test]
    public function merchandiser_can_reach_list_and_view_enabled_account(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
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
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
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
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount();

        $query = ConnectorAccount::query();
        $query = ConnectorAccountMerchandiserPresentation::applySafeQuery($query, $merchandiser);

        $record = $query->whereKey($account->id)->firstOrFail();

        $this->assertFalse($record->offsetExists('credentials'));
        $this->assertFalse($record->offsetExists('settings'));
        $this->assertFalse($record->offsetExists('base_url'));
        $this->assertFalse($record->offsetExists('store_code'));
        $this->assertTrue($record->offsetExists('auth_profile'));
        $this->assertTrue($record->offsetExists('name'));
        $this->assertTrue($record->offsetExists('connection_status'));
    }

    #[Test]
    public function merchandiser_sanitize_record_hides_sensitive_attributes(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $account = $this->createConnectorAccount(overrides: [
            'settings' => ['secret' => self::SETTINGS_CANARY],
        ])->fresh();

        $sanitized = ConnectorAccountMerchandiserPresentation::sanitizeRecord($account, $merchandiser);

        foreach (ConnectorAccountMerchandiserPresentation::hiddenAttributes() as $attribute) {
            $this->assertArrayNotHasKey($attribute, $sanitized->toArray(), "Attribute [{$attribute}] must be hidden.");
        }
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
}
