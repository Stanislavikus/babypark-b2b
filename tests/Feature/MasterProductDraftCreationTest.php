<?php

namespace Tests\Feature;

use App\Enums\ProductLifecycleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Catalog\MasterProductDraftCreator;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesBrandFixtures;
use Tests\TestCase;

class MasterProductDraftCreationTest extends TestCase
{
    use CreatesBrandFixtures;
    use RefreshDatabase;

    #[Test]
    public function source_neutral_draft_creates_exactly_one_default_variant_without_sku(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $product = app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Source neutral product',
        ]);

        $this->assertNull($product->onec_guid);
        $this->assertNull($product->sku);
        $this->assertSame(ProductLifecycleStatus::Draft, $product->lifecycle_status);
        $this->assertFalse($product->is_active);
        $this->assertNotNull($product->product_type_id);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->sole();

        $this->assertSame($workspace->id, $variant->workspace_id);
        $this->assertNull($variant->onec_guid);
        $this->assertNull($variant->sku);
        $this->assertTrue($variant->is_active);
        $this->assertSame([], $variant->attributes);
    }

    #[Test]
    public function initial_business_identifiers_are_mirrored_to_the_hidden_default_variant_for_legacy_compatibility(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $product = app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Cable',
            'sku' => 'CAB-001',
            'barcode_ean' => '5901234123457',
        ]);

        $variant = $product->variants->sole();

        $this->assertSame('CAB-001', $product->sku);
        $this->assertSame('CAB-001', $variant->sku);
        $this->assertSame('5901234123457', $product->barcode_ean);
        $this->assertSame('5901234123457', $variant->barcode_ean);
    }

    #[Test]
    public function draft_creator_rejects_category_from_another_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign',
            'is_default' => false,
        ]);
        $foreignCategory = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Foreign category',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Wrong category product',
            'category_id' => $foreignCategory->id,
        ]);
    }

    #[Test]
    public function draft_creator_rejects_category_from_inactive_branch(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $root = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Inactive root',
            'is_active' => false,
        ]);
        $child = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Active child under inactive root',
            'parent_id' => $root->id,
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Wrong inactive category product',
            'category_id' => $child->id,
        ]);
    }

    #[Test]
    public function draft_creator_rejects_inactive_brand(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $brand = $this->brandFixture($workspace, 'Inactive brand');
        $brand->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Product with inactive brand',
            'brand_id' => $brand->id,
        ]);
    }

    #[Test]
    public function authorized_workspace_user_sees_create_product_action_on_product_list(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Product list editor',
            'email' => 'product-list-editor@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ListProducts::class)
            ->assertActionVisible('create')
            ->assertActionVisible('import_products');
    }

    #[Test]
    public function product_list_default_working_view_keeps_drafts_and_active_products_visible_but_hides_archived(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Product list lifecycle editor',
            'email' => 'product-list-lifecycle@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);

        $draft = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Visible draft',
            'lifecycle_status' => ProductLifecycleStatus::Draft,
        ]);
        $active = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Visible active',
            'lifecycle_status' => ProductLifecycleStatus::Active,
        ]);
        $archived = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Hidden archived',
            'lifecycle_status' => ProductLifecycleStatus::Archived,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ListProducts::class)
            ->assertCanSeeTableRecords([$draft, $active])
            ->assertCanNotSeeTableRecords([$archived]);
    }

    #[Test]
    public function legacy_admin_role_without_manage_products_does_not_see_create_product_action(): void
    {
        $user = User::query()->create([
            'name' => 'Legacy admin only',
            'email' => 'legacy-admin-only@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ListProducts::class)
            ->assertActionHidden('create');
    }

    #[Test]
    public function product_edit_requires_manage_products_and_matching_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign edit workspace',
            'is_default' => false,
        ]);
        $user = User::query()->create([
            'name' => 'Edit authorization actor',
            'email' => 'edit-authorization@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $localProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Local edit product',
            'is_active' => true,
        ]);
        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => null,
            'name' => 'Foreign edit product',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        $this->assertTrue(ProductResource::getEditAuthorizationResponse($localProduct)->denied());

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);

        $this->assertTrue(ProductResource::getEditAuthorizationResponse($localProduct)->allowed());
        $this->assertTrue(ProductResource::getEditAuthorizationResponse($foreignProduct)->denied());
    }

    #[Test]
    public function legacy_admin_without_manage_products_cannot_mount_product_editor(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Unauthorized product editor',
            'email' => 'unauthorized-product-editor@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Protected product',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertForbidden();
    }

    #[Test]
    public function authorized_workspace_user_can_create_product_from_filament_and_is_redirected_to_edit_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Product editor',
            'email' => 'product-editor@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $brand = $this->brandFixture($workspace, 'BabyPark Test');

        Livewire::actingAs($user)
            ->test(CreateProduct::class)
            ->fillForm([
                'name' => 'Manual master product',
                'sku' => null,
                'brand_id' => $brand->id,
                'barcode_ean' => null,
                'merchant_type' => 'test-product',
                'description' => '<p>Draft description</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Manual master product')
            ->sole();

        $this->assertNull($product->onec_guid);
        $this->assertNull($product->sku);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('BabyPark Test', $product->brand?->name);
        $this->assertSame('test-product', $product->merchant_type);
        $this->assertSame(ProductLifecycleStatus::Draft, $product->lifecycle_status);
        $this->assertFalse($product->is_active);
        $this->assertCount(1, $product->variants);
    }

    #[Test]
    public function create_card_saves_selected_lifecycle_and_direct_physical_fields_in_one_submit(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Full card creator',
            'email' => 'full-card-creator@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(CreateProduct::class)
            ->fillForm([
                'name' => 'Full card product',
                'master_lifecycle_status' => ProductLifecycleStatus::Active->value,
                'net_weight' => '1.250',
                'gross_weight' => '1.500',
                'width_mm' => 420,
                'height_mm' => 310,
                'depth_mm' => 210,
                'volume_m3' => '0.027342',
                'package_quantity' => 2,
                'package_type' => 'Коробка',
                'units_per_box' => 4,
                'boxes_per_pallet' => 12,
                'lead_time_days' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Full card product')
            ->sole();

        $this->assertSame(ProductLifecycleStatus::Active, $product->lifecycle_status);
        $this->assertTrue($product->is_active);
        $this->assertSame('1.250', $product->net_weight);
        $this->assertSame('1.500', $product->gross_weight);
        $this->assertSame(420, $product->width_mm);
        $this->assertSame(310, $product->height_mm);
        $this->assertSame(210, $product->depth_mm);
        $this->assertSame('0.027342', $product->volume_m3);
        $this->assertSame(2, $product->package_quantity);
        $this->assertSame('Коробка', $product->package_type);
        $this->assertSame(4, $product->units_per_box);
        $this->assertSame(12, $product->boxes_per_pallet);
        $this->assertSame(3, $product->lead_time_days);
    }

    #[Test]
    public function onec_owned_identity_fields_remain_read_only_in_existing_product_editor(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $user = User::query()->create([
            'name' => 'Existing product editor',
            'email' => 'existing-product-editor@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->grantWorkspacePermission($workspace, $user, WorkspacePermissions::MANAGE_PRODUCTS);
        $erpBrand = $this->brandFixture($workspace, 'ERP brand');
        $overrideBrand = $this->brandFixture($workspace, 'Override brand');

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ERP-001',
            'barcode_ean' => '1111111111111',
            'name' => 'ERP product',
            'brand_id' => $erpBrand->id,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'sku' => 'OVERRIDE-SKU',
                'name' => 'Override name',
                'brand_id' => $overrideBrand->id,
                'barcode_ean' => '2222222222222',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('ERP-001', $product->sku);
        $this->assertSame('ERP product', $product->name);
        $this->assertSame($erpBrand->id, $product->brand_id);
        $this->assertSame('ERP brand', $product->brand?->name);
        $this->assertSame('1111111111111', $product->barcode_ean);
    }

    private function grantWorkspacePermission(Workspace $workspace, User $user, string $code): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Product editor',
        ]);

        $permission = WorkspacePermission::query()->where('code', $code)->sole();

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspace->id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);
    }
}
