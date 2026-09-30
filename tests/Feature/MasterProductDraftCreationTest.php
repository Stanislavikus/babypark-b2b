<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
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
use Tests\TestCase;

class MasterProductDraftCreationTest extends TestCase
{
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
        $this->assertNotNull($product->product_type_id);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->sole();

        $this->assertSame($workspace->id, $variant->workspace_id);
        $this->assertNull($variant->onec_guid);
        $this->assertNull($variant->sku);
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

        Livewire::actingAs($user)
            ->test(CreateProduct::class)
            ->fillForm([
                'name' => 'Manual master product',
                'sku' => null,
                'brand' => 'BabyPark Test',
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
        $this->assertSame('BabyPark Test', $product->brand);
        $this->assertSame('test-product', $product->merchant_type);
        $this->assertCount(1, $product->variants);
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

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ERP-001',
            'barcode_ean' => '1111111111111',
            'name' => 'ERP product',
            'brand' => 'ERP brand',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'sku' => 'OVERRIDE-SKU',
                'name' => 'Override name',
                'brand' => 'Override brand',
                'barcode_ean' => '2222222222222',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('ERP-001', $product->sku);
        $this->assertSame('ERP product', $product->name);
        $this->assertSame('ERP brand', $product->brand);
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
