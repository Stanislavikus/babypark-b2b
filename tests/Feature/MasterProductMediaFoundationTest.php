<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductMediaMutationService;
use App\Services\Catalog\ProductMediaReadService;
use App\Support\Catalog\Exceptions\ProductMediaException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MasterProductMediaFoundationTest extends TestCase
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
        $this->actor = User::factory()->create(['role' => UserRole::Admin]);
        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Media manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function workspace_media_action_uploads_original_and_renders_gallery(): void
    {
        Storage::fake('public');
        $product = $this->product();
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee(__('media_ui.drop_heading'))
            ->assertSee(__('media_ui.select_existing'))
            ->assertSee(__('media_ui.add_from_url'))
            ->assertActionVisible('add_media')
            ->callAction('add_media', [
                'files' => [UploadedFile::fake()->image('ui-original.jpg', 1000, 750)],
            ])
            ->assertNotified()
            ->assertSee('1 медіа');

        $link = ProductMedia::withoutWorkspaceScope()->where('product_id', $product->id)->sole();
        $this->assertSame(MediaRole::Primary, $link->role);
        $this->assertSame(0, $link->sort_order);
        $this->assertSame('ui-original.jpg', $link->asset()->withoutGlobalScopes()->sole()->original_filename);
    }

    #[Test]
    public function workspace_media_actions_reorder_and_remove_primary_without_deleting_asset(): void
    {
        $product = $this->product();
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $first = $this->externalAsset('https://cdn.example.test/ui-first.jpg');
        $second = $this->externalAsset('https://cdn.example.test/ui-second.jpg');

        $firstLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $first->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        $secondLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $second->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 1,
            'locale' => null,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('reorder_media')
            ->callAction('reorder_media', [
                'items' => [
                    ['id' => (string) $secondLink->id],
                    ['id' => (string) $firstLink->id],
                ],
            ])
            ->assertNotified()
            ->assertActionVisible('remove_media')
            ->callAction('remove_media', ['media_id' => (string) $secondLink->id])
            ->assertNotified();

        $remaining = ProductMedia::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->sole();

        $this->assertSame((string) $firstLink->id, (string) $remaining->id);
        $this->assertSame(MediaRole::Primary, $remaining->role);
        $this->assertSame(0, $remaining->sort_order);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
        $this->assertSame(['https://cdn.example.test/ui-first.jpg'], $product->fresh()->images);
    }

    #[Test]
    public function inline_media_library_reuses_only_same_workspace_original_and_preserves_gallery_order(): void
    {
        $product = $this->product();
        $first = $this->externalAsset('https://cdn.example.test/reused-first.jpg');
        $second = $this->externalAsset('https://cdn.example.test/reused-second.jpg');
        $service = app(ProductMediaMutationService::class);

        $initial = $service->addExistingImages($this->actor, $this->workspace, $product, [
            (string) $first->id,
            (string) $first->id,
        ]);
        $this->assertCount(1, $initial);
        $this->assertSame(MediaRole::Primary, $initial->first()->role);
        $this->assertSame(0, $initial->first()->sort_order);

        $updated = $service->addExistingImages($this->actor, $this->workspace, $product, [
            (string) $first->id,
            (string) $second->id,
        ]);
        $this->assertCount(2, $updated);
        $this->assertSame([(string) $first->id, (string) $second->id], $updated->pluck('media_asset_id')->map('strval')->all());
        $this->assertSame(MediaRole::Primary, $updated->first()->role);
        $this->assertSame(MediaRole::Gallery, $updated->last()->role);
        $this->assertSame([0, 1], $updated->pluck('sort_order')->all());
        $this->assertSame([
            'https://cdn.example.test/reused-first.jpg',
            'https://cdn.example.test/reused-second.jpg',
        ], $product->fresh()->images);
        $this->assertDatabaseCount('media_assets', 2);
    }

    #[Test]
    public function inline_media_library_rejects_foreign_or_derived_asset_without_partial_association(): void
    {
        $product = $this->product();
        $valid = $this->externalAsset('https://cdn.example.test/valid-original.jpg');
        $foreign = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => Workspace::query()->create([
                'name' => 'Other workspace for isolated Media test',
                'is_default' => false,
            ])->id,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/foreign.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Pending,
        ]);
        $derivative = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $valid->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derivative.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Pending,
        ]);

        foreach ([$foreign, $derivative] as $invalid) {
            try {
                app(ProductMediaMutationService::class)->addExistingImages(
                    $this->actor,
                    $this->workspace,
                    $product,
                    [(string) $valid->id, (string) $invalid->id],
                );
                $this->fail('Foreign workspace and derived media must never be attached.');
            } catch (ProductMediaException $exception) {
                $this->assertSame(0, ProductMedia::withoutWorkspaceScope()->where('product_id', $product->id)->count());
            }
        }
    }

    #[Test]
    public function inline_product_page_action_attaches_existing_original_without_copying_it(): void
    {
        $product = $this->product();
        $asset = $this->externalAsset('https://cdn.example.test/inline-selected.jpg');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->call('addExistingMediaFromForm', (string) $asset->id)
            ->assertNotified()
            ->assertSee('1 медіа');

        $link = ProductMedia::withoutWorkspaceScope()->where('product_id', $product->id)->sole();
        $this->assertSame((string) $asset->id, (string) $link->media_asset_id);
        $this->assertSame(MediaRole::Primary, $link->role);
        $this->assertSame(0, $link->sort_order);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    #[Test]
    public function inline_media_library_requires_manage_products_permission(): void
    {
        $product = $this->product();
        $asset = $this->externalAsset('https://cdn.example.test/not-authorized.jpg');
        $other = User::factory()->create();
        $this->makeWorkspaceMembership($this->workspace, $other);

        $this->expectException(AuthorizationException::class);
        app(ProductMediaMutationService::class)->addExistingImages(
            $other,
            $this->workspace,
            $product,
            [(string) $asset->id],
        );
    }

    #[Test]
    public function media_mutation_requires_manage_products_permission(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $other = User::factory()->create();
        $this->makeWorkspaceMembership($this->workspace, $other);

        $this->expectException(AuthorizationException::class);

        app(ProductMediaMutationService::class)->addUploadedImages(
            $other,
            $this->workspace,
            $product,
            [UploadedFile::fake()->image('unauthorized.jpg', 640, 480)],
        );
    }

    #[Test]
    public function stale_reorder_is_rejected_without_partial_mutation(): void
    {
        $product = $this->product();
        $first = $this->externalAsset('https://cdn.example.test/stale-first.jpg');
        $second = $this->externalAsset('https://cdn.example.test/stale-second.jpg');

        $firstLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $first->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        $secondLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $second->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 1,
            'locale' => null,
        ]);

        try {
            app(ProductMediaMutationService::class)->reorder(
                $this->actor,
                $this->workspace,
                $product,
                [(string) $secondLink->id],
            );
            $this->fail('Expected incomplete reorder to fail closed.');
        } catch (ProductMediaException $e) {
            $this->assertStringContainsString('застарів або неповний', $e->getMessage());
        }

        $current = ProductMedia::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->orderBy('sort_order')
            ->get();

        $this->assertSame((string) $firstLink->id, (string) $current[0]->id);
        $this->assertSame(MediaRole::Primary, $current[0]->role);
        $this->assertSame(0, $current[0]->sort_order);
        $this->assertSame((string) $secondLink->id, (string) $current[1]->id);
        $this->assertSame(MediaRole::Gallery, $current[1]->role);
        $this->assertSame(1, $current[1]->sort_order);
    }

    #[Test]
    public function direct_legacy_image_write_is_blocked_once_master_media_schema_exists(): void
    {
        $product = $this->product();

        $this->expectException(ProductMediaException::class);
        $this->expectExceptionMessage('compatibility projection');

        $product->update(['images' => ['https://legacy.example.test/forbidden.jpg']]);
    }

    #[Test]
    public function upload_does_not_silently_replace_unmigrated_legacy_media(): void
    {
        Storage::fake('public');
        $product = $this->product([
            'https://legacy.example.test/valid.jpg',
            '',
        ]);
        $before = $product->images;

        try {
            app(ProductMediaMutationService::class)->addUploadedImages(
                $this->actor,
                $this->workspace,
                $product,
                [UploadedFile::fake()->image('new.jpg', 800, 600)],
            );
            $this->fail('Expected unmigrated legacy media to block first-class mutation.');
        } catch (ProductMediaException $e) {
            $this->assertStringContainsString('Legacy-медіа', $e->getMessage());
        }

        $this->assertSame($before, $product->fresh()->images);
        $this->assertSame(0, ProductMedia::withoutWorkspaceScope()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function first_class_product_media_overrides_legacy_json_in_order(): void
    {
        $product = $this->product(['https://legacy.example.test/old.jpg']);
        $first = $this->externalAsset('https://cdn.example.test/first.jpg');
        $second = $this->externalAsset('https://cdn.example.test/second.jpg');

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $first->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $second->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 1,
            'locale' => null,
        ]);

        $this->assertSame([
            'https://cdn.example.test/first.jpg',
            'https://cdn.example.test/second.jpg',
        ], app(ProductMediaReadService::class)->orderedUrls($product));
    }

    #[Test]
    public function database_prevents_cross_workspace_product_asset_and_parent_links(): void
    {
        $product = $this->product();
        $other = Workspace::query()->create(['name' => 'Other media workspace']);
        $otherAsset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $other->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://other.example.test/image.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Pending,
        ]);

        try {
            DB::table('product_media')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $this->workspace->id,
                'product_id' => $product->id,
                'media_asset_id' => $otherAsset->id,
                'role' => MediaRole::Primary->value,
                'sort_order' => 0,
                'locale' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected cross-workspace ProductMedia FK rejection.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('product_media', ['media_asset_id' => $otherAsset->id]);
        }

        $this->expectException(QueryException::class);
        DB::table('media_assets')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $otherAsset->id,
            'asset_type' => MediaAssetType::Image->value,
            'source_url' => null,
            'diagnosis_status' => MediaDiagnosisStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function mysql_style_single_primary_contract_is_backed_by_database_unique_marker(): void
    {
        $product = $this->product();
        $first = $this->externalAsset('https://cdn.example.test/p1.jpg');
        $second = $this->externalAsset('https://cdn.example.test/p2.jpg');

        DB::table('product_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $first->id,
            'role' => MediaRole::Primary->value,
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('product_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $second->id,
            'role' => MediaRole::Primary->value,
            'sort_order' => 1,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function gallery_model_rejects_semantic_derivative_as_master_gallery_item(): void
    {
        $product = $this->product();
        $original = $this->externalAsset('https://cdn.example.test/original.jpg');
        $derivative = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $original->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/improved.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(LogicException::class);
        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $derivative->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);
    }

    #[Test]
    public function upload_preserves_original_diagnoses_it_and_reuses_hash_only_inside_workspace(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $secondProduct = $this->product();
        $file = UploadedFile::fake()->image('manufacturer.jpg', 1200, 900)->size(120);

        $service = app(ProductMediaMutationService::class);
        $firstGallery = $service->addUploadedImages($this->actor, $this->workspace, $product, [$file]);
        $secondGallery = $service->addUploadedImages($this->actor, $this->workspace, $secondProduct, [$file]);

        $this->assertCount(1, $firstGallery);
        $this->assertCount(1, $secondGallery);
        $this->assertSame($firstGallery->first()->media_asset_id, $secondGallery->first()->media_asset_id);

        $asset = MediaAsset::withoutWorkspaceScope()->findOrFail($firstGallery->first()->media_asset_id);
        $this->assertNull($asset->parent_media_asset_id);
        $this->assertSame(MediaDiagnosisStatus::Ready, $asset->diagnosis_status);
        $this->assertSame(1200, $asset->width_px);
        $this->assertSame(900, $asset->height_px);
        $this->assertNotNull($asset->content_sha256);
        Storage::disk('public')->assertExists($asset->storage_path);

        $otherWorkspace = Workspace::query()->create(['name' => 'Other hash workspace']);
        $other = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'asset_type' => MediaAssetType::Image,
            'content_sha256' => $asset->content_sha256,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->assertNotSame($asset->id, $other->id);
    }

    #[Test]
    public function making_primary_reorders_gallery_and_updates_legacy_projection(): void
    {
        $product = $this->product(['https://legacy.example.test/stale.jpg']);
        $first = $this->externalAsset('https://cdn.example.test/first.jpg');
        $second = $this->externalAsset('https://cdn.example.test/second.jpg');

        $firstLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $first->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);
        $secondLink = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $second->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 1,
        ]);

        $gallery = app(ProductMediaMutationService::class)->makePrimary(
            $this->actor,
            $this->workspace,
            $product,
            (string) $secondLink->id,
        );

        $this->assertSame((string) $secondLink->id, (string) $gallery[0]->id);
        $this->assertSame(MediaRole::Primary, $gallery[0]->role);
        $this->assertSame(0, $gallery[0]->sort_order);
        $this->assertSame((string) $firstLink->id, (string) $gallery[1]->id);
        $this->assertSame(MediaRole::Gallery, $gallery[1]->role);
        $this->assertSame([
            'https://cdn.example.test/second.jpg',
            'https://cdn.example.test/first.jpg',
        ], $product->fresh()->images);
    }

    #[Test]
    public function deleting_product_removes_association_but_keeps_reusable_asset(): void
    {
        $product = $this->product();
        $asset = $this->externalAsset('https://cdn.example.test/reusable.jpg');

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $product->delete();

        $this->assertDatabaseMissing('product_media', ['product_id' => $product->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    private function product(array $images = []): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Media Product '.uniqid(),
            'images' => $images,
            'is_active' => true,
        ]);
    }

    private function externalAsset(string $url): MediaAsset
    {
        return MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => $url,
            'diagnosis_status' => MediaDiagnosisStatus::Pending,
            'provenance_json' => ['kind' => 'test_external'],
        ]);
    }
}
