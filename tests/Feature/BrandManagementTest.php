<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\UserRole;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class BrandManagementTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Brand manager',
            [WorkspacePermissions::MANAGE_PRODUCTS],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    #[Test]
    public function brand_identity_is_uuid_exact_duplicate_is_rejected_and_case_variant_stays_distinct(): void
    {
        $manager = app(BrandManager::class);

        $first = $manager->create($this->actor, $this->workspace, 'Joolz');
        $caseVariant = $manager->create($this->actor, $this->workspace, 'joolz');

        $this->assertNotSame($first->id, $caseVariant->id);
        $this->assertSame('Joolz', $first->name);
        $this->assertSame('joolz', $caseVariant->name);

        try {
            $manager->create($this->actor, $this->workspace, 'Joolz');
            $this->fail('Exact duplicate Brand label must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        try {
            $manager->update($this->actor, $this->workspace, $caseVariant, [
                'name' => 'Joolz',
            ]);
            $this->fail('Renaming a Brand to an exact duplicate label must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame('joolz', $caseVariant->fresh()->name);
        $this->assertSame(2, Brand::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
    }

    #[Test]
    public function assignment_requires_active_brand_in_same_workspace(): void
    {
        $product = $this->product('Manual product');
        $active = app(BrandManager::class)->create($this->actor, $this->workspace, 'Active');
        $inactive = app(BrandManager::class)->create($this->actor, $this->workspace, 'Inactive', active: false);
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign brand workspace',
            'is_default' => false,
        ]);
        $foreign = Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign',
            'is_active' => true,
        ]);

        $assigned = app(BrandManager::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            (string) $active->id,
        );

        $this->assertSame($active->id, $assigned->brand_id);
        $this->assertSame('Active', $assigned->brand?->name);

        foreach ([$inactive, $foreign] as $rejected) {
            try {
                app(BrandManager::class)->assign(
                    $this->actor,
                    $this->workspace,
                    $product,
                    (string) $rejected->id,
                );
                $this->fail('Invalid Brand assignment must fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('brand_id', $exception->errors());
            }
        }

        $this->assertSame($active->id, $product->fresh()->brand_id);
    }

    #[Test]
    public function source_owned_product_or_variant_cannot_change_brand_assignment(): void
    {
        $current = app(BrandManager::class)->create($this->actor, $this->workspace, 'Current');
        $next = app(BrandManager::class)->create($this->actor, $this->workspace, 'Next');

        $productOwned = $this->product('Product-owned', (string) Str::uuid(), (string) $current->id);
        $variantOwned = $this->product('Variant-owned', null, (string) $current->id);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $variantOwned->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VARIANT-OWNED',
            'is_active' => true,
        ]);

        foreach ([$productOwned, $variantOwned] as $sourceOwned) {
            try {
                app(BrandManager::class)->assign(
                    $this->actor,
                    $this->workspace,
                    $sourceOwned,
                    (string) $next->id,
                );
                $this->fail('Source-owned Brand assignment must fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('brand_id', $exception->errors());
            }

            $this->assertSame($current->id, $sourceOwned->fresh()->brand_id);
        }

        $noop = app(BrandManager::class)->assign(
            $this->actor,
            $this->workspace,
            $variantOwned,
            (string) $current->id,
        );
        $this->assertSame($current->id, $noop->brand_id);
    }

    #[Test]
    public function product_card_disables_brand_for_source_owned_variant_and_manual_save_uses_governed_writer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $current = app(BrandManager::class)->create($this->actor, $this->workspace, 'Current UI Brand');
        $next = app(BrandManager::class)->create($this->actor, $this->workspace, 'Next UI Brand');

        $sourceOwned = $this->product('Source-owned UI', null, (string) $current->id);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $sourceOwned->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SOURCE-OWNED-UI',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $sourceOwned->getRouteKey()])
            ->assertFormFieldDisabled('brand_id');

        $manual = $this->product('Manual UI', null, (string) $current->id);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $manual->id,
            'onec_guid' => null,
            'sku' => 'MANUAL-UI',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $manual->getRouteKey()])
            ->fillForm(['brand_id' => $next->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $manual->refresh();
        $this->assertSame($next->id, $manual->brand_id);
        $this->assertSame('Next UI Brand', $manual->brand?->name);
    }

    #[Test]
    public function renaming_brand_used_by_source_owned_variant_fails_closed_but_non_identity_updates_remain_allowed(): void
    {
        $brand = app(BrandManager::class)->create($this->actor, $this->workspace, 'ERP Brand');
        $product = $this->product('Source-linked', null, (string) $brand->id);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ERP-BRAND-VARIANT',
            'is_active' => true,
        ]);

        try {
            app(BrandManager::class)->update($this->actor, $this->workspace, $brand, [
                'name' => 'Renamed Brand',
                'short_description' => 'Should rollback',
                'is_active' => false,
            ]);
            $this->fail('Brand rename affecting source-owned Products must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $fresh = $brand->fresh();
        $this->assertSame('ERP Brand', $fresh->name);
        $this->assertNull($fresh->short_description);
        $this->assertTrue($fresh->is_active);

        $updated = app(BrandManager::class)->update($this->actor, $this->workspace, $brand, [
            'name' => 'ERP Brand',
            'short_description' => 'Merchant metadata',
            'is_active' => false,
        ]);

        $this->assertSame('Merchant metadata', $updated->short_description);
        $this->assertFalse($updated->is_active);
    }

    #[Test]
    public function logo_reference_requires_same_workspace_original_image(): void
    {
        $original = $this->imageAsset($this->workspace, 'logo.png');
        $derivative = $this->imageAsset($this->workspace, 'logo-thumb.png', $original);
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign media workspace',
            'is_default' => false,
        ]);
        $foreign = $this->imageAsset($foreignWorkspace, 'foreign.png');

        $brand = app(BrandManager::class)->create(
            $this->actor,
            $this->workspace,
            'Logo Brand',
            logoMediaAssetId: (string) $original->id,
        );

        $this->assertSame($original->id, $brand->logo_media_asset_id);
        $this->assertSame($original->id, $brand->logo?->id);

        foreach ([$derivative, $foreign] as $rejected) {
            try {
                app(BrandManager::class)->update($this->actor, $this->workspace, $brand, [
                    'name' => $brand->name,
                    'logo_media_asset_id' => $rejected->id,
                ]);
                $this->fail('Invalid logo MediaAsset must fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('logo_media_asset_id', $exception->errors());
            }
        }

        $this->assertSame($original->id, $brand->fresh()->logo_media_asset_id);
    }

    #[Test]
    public function brand_resource_create_and_edit_use_governed_writer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $logo = $this->imageAsset($this->workspace, 'brand-ui.png');

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'UI Brand',
                'logo_media_asset_id' => $logo->id,
                'short_description' => 'Created from Brand resource',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'UI Brand')
            ->sole();

        $this->assertSame($logo->id, $brand->logo_media_asset_id);
        $this->assertSame('Created from Brand resource', $brand->short_description);
        $this->assertTrue($brand->is_active);

        Livewire::actingAs($this->actor)
            ->test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'name' => 'UI Brand renamed',
                'logo_media_asset_id' => null,
                'short_description' => 'Updated from Brand resource',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $brand->refresh();
        $this->assertSame('UI Brand renamed', $brand->name);
        $this->assertNull($brand->logo_media_asset_id);
        $this->assertSame('Updated from Brand resource', $brand->short_description);
        $this->assertFalse($brand->is_active);
    }

    #[Test]
    public function brand_logo_preview_uses_the_shared_stable_media_frame(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $logo = $this->imageAsset($this->workspace, 'wide-frame-logo.png');
        $brand = app(BrandManager::class)->create(
            $this->actor,
            $this->workspace,
            'Frame Brand',
            logoMediaAssetId: (string) $logo->id,
        );

        Livewire::actingAs($this->actor)
            ->test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->assertSeeHtml('bp-media-preview-frame--brand-form');

        Livewire::actingAs($this->actor)
            ->test(ListBrands::class)
            ->assertCanSeeTableRecords([$brand])
            ->assertSeeHtml('bp-media-preview-frame--brand-list');
    }

    #[Test]
    public function brand_can_upload_new_logo_into_assets_and_reuse_the_same_original(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $file = UploadedFile::fake()->image('brand-wide.png', 1200, 180)->size(128);

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'Uploaded Logo Brand',
                'logo_upload' => $file,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'Uploaded Logo Brand')
            ->sole();

        $asset = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->whereKey($brand->logo_media_asset_id)
            ->sole();

        $this->assertTrue($asset->isOriginal());
        $this->assertSame('brand-wide.png', $asset->original_filename);
        $this->assertSame(1200, $asset->width_px);
        $this->assertSame(180, $asset->height_px);
        Storage::disk('public')->assertExists((string) $asset->storage_path);

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'Uploaded Logo Brand Reuse',
                'logo_upload' => $file,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reusedBrand = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'Uploaded Logo Brand Reuse')
            ->sole();

        $this->assertSame($asset->id, $reusedBrand->logo_media_asset_id);
        $this->assertSame(1, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('content_sha256', $asset->content_sha256)
            ->count());
    }

    #[Test]
    public function brand_logo_admission_failure_is_a_form_error_and_creates_nothing(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $file = UploadedFile::fake()->createWithContent(
            'too-many-pixels.png',
            $this->pngHeader(5001, 5000),
        );

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'Rejected Logo Brand',
                'logo_upload' => $file,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['logo_upload']);

        $this->assertDatabaseMissing('brands', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Rejected Logo Brand',
        ]);
        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
    }

    #[Test]
    public function failed_brand_create_rolls_back_new_logo_asset_and_file(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        app(BrandManager::class)->create($this->actor, $this->workspace, 'Duplicate UI Brand');

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'Duplicate UI Brand',
                'logo_upload' => UploadedFile::fake()->image('rollback-create.png', 800, 600),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
        Storage::disk('public')->assertDirectoryEmpty('media/originals/'.$this->workspace->id);
    }

    #[Test]
    public function failed_brand_edit_rolls_back_new_logo_asset_and_file(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $target = app(BrandManager::class)->create($this->actor, $this->workspace, 'Editable Brand');
        app(BrandManager::class)->create($this->actor, $this->workspace, 'Existing Brand');

        Livewire::actingAs($this->actor)
            ->test(EditBrand::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => 'Existing Brand',
                'logo_upload' => UploadedFile::fake()->image('rollback-edit.png', 800, 600),
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasErrors(['name']);

        $target->refresh();
        $this->assertSame('Editable Brand', $target->name);
        $this->assertNull($target->logo_media_asset_id);
        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
        Storage::disk('public')->assertDirectoryEmpty('media/originals/'.$this->workspace->id);
    }

    #[Test]
    public function failed_brand_write_never_deletes_a_reused_logo_asset(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $file = UploadedFile::fake()->image('reused-on-failure.png', 640, 480);
        $asset = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $this->workspace, $file);
        $storedPath = (string) $asset->storage_path;

        app(BrandManager::class)->create($this->actor, $this->workspace, 'Duplicate With Reuse');

        Livewire::actingAs($this->actor)
            ->test(CreateBrand::class)
            ->fillForm([
                'name' => 'Duplicate With Reuse',
                'logo_upload' => $file,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($storedPath);
    }

    #[Test]
    public function unauthorized_actor_and_physical_delete_are_denied(): void
    {
        $brand = app(BrandManager::class)->create($this->actor, $this->workspace, 'Protected');
        $unauthorized = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
        $this->assertTrue(BrandResource::getDeleteAuthorizationResponse($brand)->denied());

        $this->expectException(AuthorizationException::class);

        app(BrandManager::class)->create($unauthorized, $this->workspace, 'Forbidden');
    }

    private function product(string $name, ?string $onecGuid = null, ?string $brandId = null): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => $onecGuid,
            'name' => $name,
            'brand_id' => $brandId,
            'is_active' => true,
        ]);
    }

    private function pngHeader(int $width, int $height): string
    {
        $bytes = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $bytes .= pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $bytes .= pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        return $bytes;
    }

    private function imageAsset(Workspace $workspace, string $filename, ?MediaAsset $parent = null): MediaAsset
    {
        return MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'parent_media_asset_id' => $parent?->id,
            'asset_type' => MediaAssetType::Image,
            'original_filename' => $filename,
        ]);
    }
}
