<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Resources\MediaAssetResource\Pages\ViewMediaAsset;
use App\Filament\Resources\ProductResource;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductMediaMutationService;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MediaAssetCoreTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Assets manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function standalone_and_product_upload_reuse_one_workspace_original(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('shared.png', 1200, 900)->size(256);

        $asset = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $this->workspace, $file);

        $product = $this->product();
        $gallery = app(ProductMediaMutationService::class)
            ->addUploadedImages($this->actor, $this->workspace, $product, [$file]);

        $this->assertCount(1, $gallery);
        $this->assertSame((string) $asset->id, (string) $gallery->first()->media_asset_id);
        $this->assertSame(1, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
        Storage::disk('public')->assertExists((string) $asset->storage_path);
    }

    #[Test]
    public function identical_bytes_are_deduplicated_only_inside_one_workspace(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('same.png', 640, 480);

        $first = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $this->workspace, $file);
        $second = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $this->workspace, $file);

        $this->assertSame((string) $first->id, (string) $second->id);

        $otherWorkspace = Workspace::query()->create(['name' => 'Other assets workspace']);
        $membership = $this->makeWorkspaceMembership($otherWorkspace, $this->actor);
        $role = $this->createRoleWithPermissions($otherWorkspace->id, 'Other assets manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        $other = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $otherWorkspace, $file);

        $this->assertNotSame((string) $first->id, (string) $other->id);
        $this->assertSame($first->content_sha256, $other->content_sha256);
    }

    #[Test]
    public function upload_to_unauthorized_workspace_fails_closed(): void
    {
        Storage::fake('public');
        $foreign = Workspace::query()->create(['name' => 'Foreign']);
        $file = UploadedFile::fake()->image('foreign.png', 400, 400);

        $this->expectException(AuthorizationException::class);

        app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $foreign, $file);
    }

    #[Test]
    public function hard_file_and_pixel_limits_are_enforced_before_permanent_storage(): void
    {
        Storage::fake('public');
        $service = app(OriginalImageIngestService::class);

        $atLimit = $this->pngUpload('at-limit.png', 5000, 5000);
        $prepared = $service->prepare($atLimit);
        $this->assertSame(25_000_000, $prepared->widthPx * $prepared->heightPx);

        $atByteLimit = $this->pngUpload(
            'at-byte-limit.png',
            1000,
            1000,
            OriginalImageIngestService::MAX_BYTES,
        );
        $preparedAtByteLimit = $service->prepare($atByteLimit);
        $this->assertSame(OriginalImageIngestService::MAX_BYTES, $preparedAtByteLimit->byteSize);

        try {
            $service->prepare($this->pngUpload('over-pixels.png', 5001, 5000));
            $this->fail('Image over 25 MP must be rejected.');
        } catch (MediaIngestException $e) {
            $this->assertStringContainsString('25 мегапікселів', $e->getMessage());
        }

        try {
            $service->prepare(UploadedFile::fake()->createWithContent(
                'over-bytes.png',
                str_repeat('x', OriginalImageIngestService::MAX_BYTES + 1),
            ));
            $this->fail('File over 20 MiB must be rejected.');
        } catch (MediaIngestException $e) {
            $this->assertStringContainsString('20 МіБ', $e->getMessage());
        }

        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()->count());
        Storage::disk('public')->assertDirectoryEmpty('media/originals');
    }

    #[Test]
    public function supported_original_image_headers_are_admitted_without_full_decode(): void
    {
        $fixtures = [
            'jpg' => [
                'mime' => 'image/jpeg',
                'bytes' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDi6KKK++PcP//Z',
            ],
            'png' => [
                'mime' => 'image/png',
                'bytes' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOskDvBwMDAxMDAgKAAGf4BZGzWmUMAAAAASUVORK5CYII=',
            ],
            'webp' => [
                'mime' => 'image/webp',
                'bytes' => 'UklGRjwAAABXRUJQVlA4IDAAAADwAQCdASoCAAMAAUAmJaACdLoB+AAEgwAA/vLrf/2Vj6Vj6Vj94L/4H5dOGIAAAAA=',
            ],
            'gif' => [
                'mime' => 'image/gif',
                'bytes' => 'R0lGODdhAgADAIEAAHgeyAAAAAAAAAAAACwAAAAAAgADAAAIBgABCBwYEAA7',
            ],
            'avif' => [
                'mime' => 'image/avif',
                'bytes' => 'AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAETAAAAKQAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEAAGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAAAgAAAAMAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAAAMW1kYXQSAAoIGABzRAQ0GhAyGxTHh4ZlAgggnlAAAABIWtlc1jIqYYpS8GhFiA==',
            ],
        ];

        foreach ($fixtures as $extension => $fixture) {
            $bytes = base64_decode($fixture['bytes'], true);
            $this->assertIsString($bytes);

            $prepared = app(OriginalImageIngestService::class)->prepare(
                UploadedFile::fake()->createWithContent('sample.'.$extension, $bytes),
            );

            $this->assertSame($fixture['mime'], $prepared->mimeType);
            $this->assertSame($extension, $prepared->extension);
            $this->assertSame(2, $prepared->widthPx);
            $this->assertSame(3, $prepared->heightPx);
        }
    }

    #[Test]
    public function long_untrusted_original_filename_is_bounded_as_display_metadata(): void
    {
        Storage::fake('public');
        $name = '<b>logo</b> 😀 '.str_repeat('x', 600).'.png';

        $asset = app(OriginalImageIngestService::class)->ingestStandalone(
            $this->actor,
            $this->workspace,
            $this->pngUpload($name, 320, 160),
        );

        $this->assertNotSame('', (string) $asset->original_filename);
        $this->assertLessThanOrEqual(512, mb_strlen((string) $asset->original_filename));
        Storage::disk('public')->assertExists((string) $asset->storage_path);
    }

    #[Test]
    public function svg_is_rejected_with_merchant_readable_message(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>',
        );

        $this->expectException(MediaIngestException::class);
        $this->expectExceptionMessage('SVG поки не підтримується');

        app(OriginalImageIngestService::class)->prepare($file);
    }

    #[Test]
    public function derivative_hash_collision_fails_closed_with_readable_message(): void
    {
        Storage::fake('public');
        $service = app(OriginalImageIngestService::class);
        $file = UploadedFile::fake()->image('collision.png', 300, 300);
        $prepared = $service->prepare($file);

        $original = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/original.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $original->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derived.png',
            'content_sha256' => $prepared->sha256,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(MediaIngestException::class);
        $this->expectExceptionMessage('технічна похідна');

        $service->ingestStandalone($this->actor, $this->workspace, $file);
    }

    #[Test]
    public function product_association_failure_rolls_back_new_asset_and_file(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $existing = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/localized.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $existing->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 0,
            'locale' => 'uk',
        ]);

        try {
            app(ProductMediaMutationService::class)->addUploadedImages(
                $this->actor,
                $this->workspace,
                $product,
                [UploadedFile::fake()->image('rollback.png', 800, 600)],
            );
            $this->fail('ProductMedia unique conflict must fail.');
        } catch (QueryException) {
            // Expected: product-level sort order 0 collides with the localized association.
        }

        $this->assertSame(1, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->count());
        Storage::disk('public')->assertDirectoryEmpty('media/originals/'.$this->workspace->id);
    }

    #[Test]
    public function product_rollback_never_deletes_a_reused_asset(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('reused.png', 800, 600);
        $reused = app(OriginalImageIngestService::class)
            ->ingestStandalone($this->actor, $this->workspace, $file);
        $storedPath = (string) $reused->storage_path;

        $product = $this->product();
        $other = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/localized-2.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $other->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 0,
            'locale' => 'uk',
        ]);

        try {
            app(ProductMediaMutationService::class)
                ->addUploadedImages($this->actor, $this->workspace, $product, [$file]);
            $this->fail('ProductMedia unique conflict must fail.');
        } catch (QueryException) {
            // Expected.
        }

        $this->assertDatabaseHas('media_assets', ['id' => $reused->id]);
        Storage::disk('public')->assertExists($storedPath);
    }

    #[Test]
    public function library_usage_counts_are_aggregated_without_n_plus_one(): void
    {
        $assets = collect(range(1, 40))->map(fn (int $index): MediaAsset => MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/'.$index.'.jpg',
            'original_filename' => 'asset-'.$index.'.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]));

        $product = $this->product();
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'ASSET-VARIANT',
            'attributes' => [],
            'is_active' => true,
        ]);
        $brand = Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Asset Brand',
            'logo_media_asset_id' => $assets[0]->id,
            'is_active' => true,
        ]);

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $assets[0]->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);
        DB::table('variant_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'media_asset_id' => $assets[0]->id,
            'role' => MediaRole::Primary->value,
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $records = app(MediaAssetLibraryReadService::class)
            ->originalsQuery($this->workspace)
            ->orderBy('original_filename')
            ->get();

        foreach ($records as $record) {
            app(MediaAssetLibraryReadService::class)->usageSummary($record);
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(40, $records);
        $this->assertLessThanOrEqual(2, count($queries));
        $first = $records->firstWhere('id', $assets[0]->id);
        $this->assertSame(1, (int) $first->products_usage_count);
        $this->assertSame(1, (int) $first->variants_usage_count);
        $this->assertSame(1, (int) $first->brands_usage_count);
        $this->assertSame($brand->id, Brand::withoutWorkspaceScope()->findOrFail($brand->id)->id);
    }

    #[Test]
    public function assets_resource_is_workspace_scoped_and_filename_is_rendered_as_text(): void
    {
        $safe = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/safe.jpg',
            'original_filename' => '<script>alert("x")</script> 😀 '.str_repeat('x', 120).'.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $unused = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/unused.jpg',
            'original_filename' => 'unused.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Logo usage brand',
            'logo_media_asset_id' => $safe->id,
            'is_active' => true,
        ]);

        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign list']);
        $foreign = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/foreign.jpg',
            'original_filename' => 'foreign.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ListMediaAssets::class)
            ->assertCanSeeTableRecords([$safe, $unused])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertSeeHtml('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
            ->assertDontSeeHtml('<script>alert("x")</script>')
            ->filterTable('usage', 'brand_logos')
            ->assertCanSeeTableRecords([$safe])
            ->assertCanNotSeeTableRecords([$unused, $foreign]);
    }

    #[Test]
    public function library_distinguishes_managed_external_and_attention_assets(): void
    {
        $managed = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => 'media/originals/'.$this->workspace->id.'/managed.png',
            'original_filename' => 'managed.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $external = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/external.png',
            'original_filename' => 'external.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $attention = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'original_filename' => 'attention.png',
            'diagnosis_status' => MediaDiagnosisStatus::Attention,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ListMediaAssets::class)
            ->assertCanSeeTableRecords([$managed, $external, $attention])
            ->assertSee('У платформі')
            ->assertSee('Зовнішнє')
            ->assertSee('Потребує уваги')
            ->filterTable('source', 'managed')
            ->assertCanSeeTableRecords([$managed])
            ->assertCanNotSeeTableRecords([$external, $attention])
            ->removeTableFilter('source')
            ->filterTable('attention')
            ->assertCanSeeTableRecords([$attention])
            ->assertCanNotSeeTableRecords([$managed, $external]);

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $external->getRouteKey()])
            ->assertSee('Перевірка джерела')
            ->assertSee('Відсутність попередження не означає, що посилання було нещодавно перевірено');
    }

    #[Test]
    public function asset_preview_contains_wide_images_and_details_link_real_usage(): void
    {
        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => 'media/originals/'.$this->workspace->id.'/wide-logo.png',
            'original_filename' => 'wide-logo.png',
            'mime_type' => 'image/png',
            'width_px' => 1200,
            'height_px' => 180,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $product = $this->product();
        $product->update(['name' => 'Usage Product']);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'USAGE-VARIANT',
            'attributes' => [],
            'is_active' => true,
        ]);
        $brand = Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Usage Brand',
            'logo_media_asset_id' => $asset->id,
            'is_active' => true,
        ]);

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        DB::table('variant_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary->value,
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = Livewire::actingAs($this->actor)
            ->test(ListMediaAssets::class)
            ->assertCanSeeTableRecords([$asset])
            ->assertSeeHtml('bp-media-preview-frame--asset-card')
            ->assertSee('У платформі')
            ->assertDontSee('Перевірено');

        $table = $list->instance()->getTable();
        $this->assertTrue($table->hasDeferredFilters());
        $filterTrigger = $table->getFiltersTriggerAction();
        $this->assertTrue($filterTrigger->isModalSlideOver());
        $this->assertTrue($filterTrigger->isOutlined());
        $this->assertSame('gray', $filterTrigger->getColor());
        $this->assertSame('Застосувати', $table->getFiltersApplyAction()->getLabel());
        $this->assertSame('gray', $table->getFiltersApplyAction()->getColor());

        $filterFooterActions = $filterTrigger->getExtraModalFooterActions();
        $this->assertTrue($filterFooterActions['applyFilters']->shouldClose());
        $this->assertSame('gray', $filterFooterActions['resetFilters']->getColor());
        $this->assertTrue($filterFooterActions['resetFilters']->isOutlined());
        $this->assertTrue($filterFooterActions['resetFilters']->shouldClose());

        $list->mountTableAction('inspect', $asset);

        $mountedView = $list->instance()->getMountedAction();
        $this->assertNotNull($mountedView);
        $this->assertSame('inspect', $mountedView->getName());
        $this->assertTrue($mountedView->hasModal());
        $this->assertTrue($mountedView->isModalSlideOver());
        $this->assertNull($mountedView->getUrl());
        $this->assertArrayHasKey('open_full_page_footer', $mountedView->getExtraModalFooterActions());
        $this->assertTrue($mountedView->getExtraModalFooterActions()['open_full_page_footer']->shouldOpenUrlInNewTab());

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertSeeHtml('bp-media-preview-frame--detail')
            ->assertSee('Технічний стан')
            ->assertSee('Перевірено')
            ->assertSee('0,22 МП')
            ->assertSeeHtml('fi-in-entry-label fi-sr-only')
            ->assertSee('Бренд · Usage Brand')
            ->assertSee('Товар · Usage Product')
            ->assertSee('Варіант · USAGE-VARIANT · Usage Product')
            ->assertSee(BrandResource::getUrl('edit', ['record' => $brand]))
            ->assertSee(ProductResource::getUrl('edit', ['record' => $product]));
    }

    #[Test]
    public function shared_media_frame_styles_target_the_filament_image_container_itself(): void
    {
        $theme = file_get_contents(resource_path('css/filament/theme.css'));

        $this->assertIsString($theme);
        $this->assertStringContainsString('.bp-media-preview-frame.fi-in-image', $theme);
        $this->assertStringContainsString('.bp-media-preview-frame.fi-ta-image', $theme);
        $this->assertStringContainsString('aspect-ratio: 1 / 1;', $theme);
        $this->assertStringContainsString('height: auto;', $theme);
        $this->assertStringContainsString('object-fit: contain !important;', $theme);
        $this->assertStringContainsString('--bp-media-preview-width: 4rem;', $theme);
        $this->assertStringContainsString('--bp-media-preview-width: 9rem;', $theme);
        $this->assertStringContainsString('--bp-media-preview-width: min(100%, 18rem);', $theme);
        $this->assertStringNotContainsString('--bp-media-preview-height:', $theme);
    }

    #[Test]
    public function unused_asset_detail_is_structured_and_small_image_megapixels_remain_informative(): void
    {
        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => 'media/originals/'.$this->workspace->id.'/small-logo.png',
            'original_filename' => 'small-logo.png',
            'mime_type' => 'image/png',
            'byte_size' => 3072,
            'width_px' => 140,
            'height_px' => 27,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->assertSame('0,004 МП', MediaAssetResource::megapixels($asset));

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertSee('Файл')
            ->assertSee('small-logo.png')
            ->assertSee('Розмір')
            ->assertSee('140 × 27 px')
            ->assertSee('Мегапікселі')
            ->assertSee('0,004 МП')
            ->assertSee('Вага')
            ->assertSee('3 КіБ')
            ->assertSee('Формат')
            ->assertSee('image/png')
            ->assertSee('Зберігання')
            ->assertSee('Технічний стан')
            ->assertSee('Додано')
            ->assertSee('Не використовується');
    }

    #[Test]
    public function forged_foreign_asset_record_cannot_be_opened(): void
    {
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign direct view']);
        $foreign = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/foreign-direct.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $foreign->getRouteKey()]);
    }

    #[Test]
    public function used_and_unused_filters_include_derivative_lineage(): void
    {
        $original = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/original-for-derivative.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $unused = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/truly-unused.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $original->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derived-for-usage.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ListMediaAssets::class)
            ->filterTable('usage', 'used')
            ->assertCanSeeTableRecords([$original])
            ->assertCanNotSeeTableRecords([$unused])
            ->removeTableFilter('usage')
            ->filterTable('usage', 'unused')
            ->assertCanSeeTableRecords([$unused])
            ->assertCanNotSeeTableRecords([$original]);
    }

    #[Test]
    public function assets_page_upload_uses_shared_ingest_and_layout_toggle(): void
    {
        Storage::fake('public');

        $component = Livewire::actingAs($this->actor)
            ->test(ListMediaAssets::class)
            ->assertActionVisible('upload_assets');

        $uploadAction = $component->instance()->getAction('upload_assets');
        $this->assertNotNull($uploadAction);
        $this->assertSame('Завантажити', $uploadAction->getModalSubmitActionLabel());

        $component
            ->assertSet('assetLayout', 'grid')
            ->callAction('toggle_layout')
            ->assertSet('assetLayout', 'list')
            ->callAction('upload_assets', [
                'files' => [UploadedFile::fake()->image('library-upload.png', 900, 700)],
            ])
            ->assertNotified();

        $asset = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('original_filename', 'library-upload.png')
            ->sole();

        $this->assertNull($asset->parent_media_asset_id);
        $this->assertSame(MediaDiagnosisStatus::Ready, $asset->diagnosis_status);
        Storage::disk('public')->assertExists((string) $asset->storage_path);
    }

    #[Test]
    public function user_without_manage_products_can_view_library_but_cannot_upload(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeWorkspaceMembership($this->workspace, $viewer);

        Livewire::actingAs($viewer)
            ->test(ListMediaAssets::class)
            ->assertActionHidden('upload_assets');
    }

    private function product(): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Assets Product '.uniqid(),
            'images' => [],
            'is_active' => true,
        ]);
    }

    private function pngUpload(
        string $name,
        int $width,
        int $height,
        ?int $targetBytes = null,
    ): UploadedFile {
        $header = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $header .= pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $header .= pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        if ($targetBytes !== null && $targetBytes > strlen($header)) {
            $header = str_pad($header, $targetBytes, "\0");
        }

        return UploadedFile::fake()->createWithContent($name, $header);
    }
}
