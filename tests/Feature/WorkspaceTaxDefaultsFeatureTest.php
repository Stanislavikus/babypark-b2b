<?php

namespace Tests\Feature;

use App\Enums\PriceDisplayMode;
use App\Enums\UserRole;
use App\Filament\Pages\WorkspaceTaxSettings;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\WorkspaceTaxDefaults;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class WorkspaceTaxDefaultsFeatureTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(WorkspacePermissions::MANAGE_TAX_SETTINGS, 'web');
        $this->workspace = $this->defaultWorkspace();
        $this->workspace->update([
            'default_vat_rate' => 20,
            'default_price_display_mode' => PriceDisplayMode::TaxInclusivePrimary,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_access_workspace_tax_settings_page(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/workspace-tax-settings')
            ->assertOk();
    }

    public function test_manager_without_permission_cannot_access_workspace_tax_settings(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get('/admin/workspace-tax-settings')
            ->assertForbidden();
    }

    public function test_manager_with_permission_can_access_workspace_tax_settings(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager Allowed',
            'email' => 'manager-allowed-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);
        $manager->givePermissionTo(WorkspacePermissions::MANAGE_TAX_SETTINGS);

        $this->actingAs($manager)
            ->get('/admin/workspace-tax-settings')
            ->assertOk();
    }

    public function test_vat_rate_change_warning_not_shown_when_only_display_mode_changes(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Display Only',
            'email' => 'admin-display-only@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_price_display_mode', PriceDisplayMode::TaxExclusivePrimary->value)
            ->call('save');

        $this->workspace->refresh();
        $this->assertSame(PriceDisplayMode::TaxExclusivePrimary, $this->workspace->default_price_display_mode);
        $this->assertSame('20.00', (string) $this->workspace->default_vat_rate);
    }

    public function test_vat_rate_change_warning_shown_when_rate_changes(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Changed',
            'email' => 'admin-changed-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_vat_rate', 19)
            ->call('save')
            ->assertActionExists('confirmSaveWithVatChange');
    }

    public function test_items_relation_manager_uses_workspace_default_vat_rate_not_config(): void
    {
        config(['pricing.default_vat_rate' => 20]);
        $this->workspace->update(['default_vat_rate' => 19]);

        $priceList = $this->createPriceList($this->workspace, isDefault: true);
        $priceList->load('workspace');

        $admin = User::query()->create([
            'name' => 'Admin Items',
            'email' => 'admin-items-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $priceList,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ]);

        $reflection = new ReflectionClass(ItemsRelationManager::class);
        $method = $reflection->getMethod('workspaceDefaultVatRate');
        $method->setAccessible(true);

        $rate = $method->invoke($component->instance());

        $this->assertSame(19.0, $rate);
        $this->assertSame(19.0, app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate($this->workspace->fresh()));
    }

    public function test_items_relation_manager_memoizes_workspace_default_vat_rate_lookup(): void
    {
        $this->workspace->update(['default_vat_rate' => 19]);

        $admin = User::query()->create([
            'name' => 'Admin Memo',
            'email' => 'admin-memo-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $priceList = $this->createPriceList($this->workspace, isDefault: false);

        $component = Livewire::actingAs($admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $priceList,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ]);

        $reflection = new ReflectionClass(ItemsRelationManager::class);
        $method = $reflection->getMethod('workspaceDefaultVatRate');
        $method->setAccessible(true);

        $manager = $component->instance();

        $method->invoke($manager);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = $method->invoke($manager);
        $second = $method->invoke($manager);

        $workspaceQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'workspaces'))
            ->count();

        $this->assertSame(19.0, $first);
        $this->assertSame(19.0, $second);
        $this->assertSame(0, $workspaceQueries);
    }
}
