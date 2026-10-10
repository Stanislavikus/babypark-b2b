<?php

namespace Tests\Feature;

use App\Enums\PriceDisplayMode;
use App\Enums\UserRole;
use App\Filament\Pages\WorkspaceTaxSettings;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Pricing\WorkspaceTaxDefaults;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceTaxDefaultsFeatureTest extends TestCase
{
    use CreatesPricingFixtures;
    use InteractsWithWorkspaceRbac {
        InteractsWithWorkspaceRbac::defaultWorkspace insteadof CreatesPricingFixtures;
    }
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        Permission::findOrCreate('legacy-spatie-tax-permission');
        $this->workspace = $this->defaultWorkspace();
        $this->workspace->update([
            'default_vat_rate' => 20,
            'default_price_display_mode' => PriceDisplayMode::TaxInclusivePrimary,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function grantTaxManage(User $user): void
    {
        $membership = $this->makeWorkspaceMembership($this->workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Tax Manager '.$user->id,
            [WorkspacePermissions::MANAGE_TAX_SETTINGS],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    private function createTaxManager(): User
    {
        $user = User::query()->create([
            'name' => 'Tax Manager',
            'email' => 'tax-manager-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);
        $this->grantTaxManage($user);

        return $user;
    }

    public function test_workspace_rbac_holder_can_access_workspace_tax_settings_page(): void
    {
        $user = $this->createTaxManager();

        $this->actingAs($user)
            ->get('/admin/workspace-tax-settings')
            ->assertOk();
    }

    public function test_legacy_admin_without_workspace_rbac_cannot_access_workspace_tax_settings(): void
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
            ->assertForbidden();
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
        $this->makeWorkspaceMembership($this->workspace, $manager, true);

        $this->actingAs($manager)
            ->get('/admin/workspace-tax-settings')
            ->assertForbidden();
    }

    public function test_spatie_grant_without_workspace_rbac_cannot_access_workspace_tax_settings(): void
    {
        $manager = User::query()->create([
            'name' => 'Spatie Only',
            'email' => 'spatie-only-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);
        $manager->givePermissionTo('legacy-spatie-tax-permission');

        $this->actingAs($manager)
            ->get('/admin/workspace-tax-settings')
            ->assertForbidden();
    }

    public function test_workspace_a_permission_cannot_manage_workspace_b_tax_settings(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other Workspace',
            'is_default' => false,
        ]);

        $user = User::query()->create([
            'name' => 'Cross Workspace',
            'email' => 'cross-workspace-tax@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        $membership = $this->makeWorkspaceMembership($otherWorkspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $otherWorkspace->id,
            'Other Tax Manager',
            [WorkspacePermissions::MANAGE_TAX_SETTINGS],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->actingAs($user)
            ->get('/admin/workspace-tax-settings')
            ->assertForbidden();
    }

    public function test_vat_rate_change_warning_not_shown_when_only_display_mode_changes(): void
    {
        $user = $this->createTaxManager();

        Livewire::actingAs($user)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_price_display_mode', PriceDisplayMode::TaxExclusivePrimary->value)
            ->call('save');

        $this->workspace->refresh();
        $this->assertSame(PriceDisplayMode::TaxExclusivePrimary, $this->workspace->default_price_display_mode);
        $this->assertSame('20.00', (string) $this->workspace->default_vat_rate);
    }

    public function test_vat_rate_change_warning_shown_when_rate_changes(): void
    {
        $user = $this->createTaxManager();

        Livewire::actingAs($user)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_vat_rate', 19)
            ->call('save')
            ->assertActionMounted('confirmSaveWithVatChange')
            ->assertMountedActionModalSee('Підтвердження зміни ставки');
    }

    public function test_revoked_permission_blocks_ordinary_save_persistence(): void
    {
        $user = $this->createTaxManager();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_price_display_mode', PriceDisplayMode::TaxExclusivePrimary->value);

        WorkspaceUser::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $component->call('save')
            ->assertStatus(403);

        $this->workspace->refresh();
        $this->assertSame(PriceDisplayMode::TaxInclusivePrimary, $this->workspace->default_price_display_mode);
    }

    public function test_revoked_permission_blocks_vat_confirmation_persistence(): void
    {
        $user = $this->createTaxManager();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceTaxSettings::class)
            ->set('data.default_vat_rate', 19)
            ->call('save')
            ->assertActionMounted('confirmSaveWithVatChange');

        WorkspaceUser::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $component->callMountedAction()
            ->assertStatus(403);

        $this->workspace->refresh();
        $this->assertSame('20.00', (string) $this->workspace->default_vat_rate);
    }

    public function test_items_relation_manager_uses_workspace_default_vat_rate_not_config(): void
    {
        config(['pricing.default_vat_rate' => 20]);
        $this->workspace->update(['default_vat_rate' => 19]);

        $priceList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->firstOrFail();
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
