<?php

namespace Tests\Feature\Connectors\Integrations;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\UserRole;
use App\Models\ConnectorDefinition;
use App\Support\Connectors\Integrations\EligibleConnectorPlatformCatalog;
use App\Support\Platform\PlatformAdminAuthorization;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class EligibleConnectorPlatformCatalogTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
    }

    #[Test]
    public function adobe_active_with_account_setup_is_visible_without_accounts(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $codes = $platforms->map->code->all();

        $this->assertContains('adobe_commerce', $codes);
        $this->assertNotContains('shopify', $codes);
        $this->assertNotContains('google_merchant', $codes);
        $this->assertNotContains('bigcommerce', $codes);
        $this->assertNotContains('csv', $codes);
    }

    #[Test]
    public function active_platform_with_existing_account_remains_visible_without_account_setup(): void
    {
        $shopify = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();
        $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $shopify->id,
            'name' => 'Shopify store',
        ]);

        $user = $this->createStaffUser(UserRole::Admin);
        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $this->assertTrue($platforms->contains(fn ($p): bool => $p->code === 'shopify'));
        $this->assertFalse($platforms->contains(fn ($p): bool => $p->code === 'google_merchant'));
    }

    #[Test]
    public function deprecated_platform_appears_only_with_non_deleted_account(): void
    {
        $definition = ConnectorDefinition::query()->create([
            'code' => 'legacy_magento',
            'name' => 'Legacy Magento',
            'direction' => ConnectorDirection::Both,
            'status' => ConnectorDefinitionStatus::Deprecated,
        ]);

        $user = $this->createStaffUser(UserRole::Admin);
        $catalog = app(EligibleConnectorPlatformCatalog::class);

        $withoutAccount = $catalog->forWorkspace($user, $this->defaultWorkspace());
        $this->assertFalse($withoutAccount->contains(fn ($p): bool => $p->code === 'legacy_magento'));

        $account = $this->createConnectorAccount($this->defaultWorkspace(), [
            'connector_definition_id' => $definition->id,
            'name' => 'Legacy store',
        ]);

        $withAccount = $catalog->forWorkspace($user, $this->defaultWorkspace());
        $this->assertTrue($withAccount->contains(fn ($p): bool => $p->code === 'legacy_magento'));

        $account->delete();

        $afterDelete = $catalog->forWorkspace($user, $this->defaultWorkspace());
        $this->assertFalse($afterDelete->contains(fn ($p): bool => $p->code === 'legacy_magento'));
    }

    #[Test]
    public function draft_platforms_never_appear(): void
    {
        $user = $this->createStaffUser(UserRole::Admin);
        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $this->assertFalse($platforms->contains(fn ($p): bool => $p->code === 'bigcommerce'));
        $this->assertFalse($platforms->contains(fn ($p): bool => $p->status === ConnectorDefinitionStatus::Draft));
    }

    #[Test]
    public function catalog_does_not_require_platform_admin_authorization(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);

        $this->assertFalse(PlatformAdminAuthorization::canManage($user));

        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $this->assertTrue($platforms->contains(fn ($p): bool => $p->code === 'adobe_commerce'));
    }

    #[Test]
    public function manager_without_connector_ability_cannot_read_catalog(): void
    {
        $user = $this->createStaffUser(UserRole::Manager);

        $this->expectException(AuthorizationException::class);

        app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());
    }
}
