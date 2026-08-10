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
    public function active_platforms_are_visible_and_drafts_are_not(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);
        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $codes = $platforms->map->code->all();

        $this->assertContains('adobe_commerce', $codes);
        $this->assertContains('shopify', $codes);
        $this->assertContains('google_merchant', $codes);
        $this->assertNotContains('bigcommerce', $codes);
        $this->assertNotContains('csv', $codes);
        $this->assertTrue($platforms->every(
            fn ($platform): bool => in_array($platform->status, [
                ConnectorDefinitionStatus::Active,
                ConnectorDefinitionStatus::Deprecated,
            ], true),
        ));
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
    public function catalog_does_not_require_platform_admin_authorization(): void
    {
        $user = $this->createStaffUser(UserRole::Merchandiser);

        $this->assertFalse(PlatformAdminAuthorization::canManage($user));

        $platforms = app(EligibleConnectorPlatformCatalog::class)
            ->forWorkspace($user, $this->defaultWorkspace());

        $this->assertNotEmpty($platforms);
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
