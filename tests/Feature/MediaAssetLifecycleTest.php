<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\MediaAssetResource\Pages\ViewMediaAsset;
use App\Jobs\Media\RetiredMediaPathCleanupJob;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Workspace\WorkspacePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MediaAssetLifecycleTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Asset lifecycle manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function replace_preserves_asset_identity_and_all_usage_foreign_keys(): void
    {
        Storage::fake('public');
        Queue::fake();

        $asset = $this->managedAsset('old.png', 600, 400);
        $oldId = (string) $asset->id;
        $oldPath = (string) $asset->storage_path;
        $oldHash = (string) $asset->content_sha256;
        [$productMedia, $variantMedia, $brand] = $this->attachEveryUsage($asset);

        $result = app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $asset,
            UploadedFile::fake()->image('new.png', 900, 700),
        );

        $fresh = MediaAsset::withoutWorkspaceScope()->findOrFail($oldId);

        $this->assertTrue($result->replaced);
        $this->assertSame($oldId, (string) $fresh->id);
        $this->assertNotSame($oldHash, (string) $fresh->content_sha256);
        $this->assertNotSame($oldPath, (string) $fresh->storage_path);
        $this->assertSame('new.png', $fresh->original_filename);
        $this->assertNull($fresh->source_url);
        $this->assertSame(MediaDiagnosisStatus::Ready, $fresh->diagnosis_status);
        $this->assertSame($oldId, (string) $productMedia->fresh()->media_asset_id);
        $this->assertSame($oldId, (string) $variantMedia->fresh()->media_asset_id);
        $this->assertSame($oldId, (string) $brand->fresh()->logo_media_asset_id);
        $this->assertSame(MediaRole::Primary, $productMedia->fresh()->role);
        $this->assertSame(MediaRole::Primary, $variantMedia->fresh()->role);
        Storage::disk('public')->assertExists((string) $fresh->storage_path);
        Storage::disk('public')->assertExists($oldPath);

        Queue::assertPushed(RetiredMediaPathCleanupJob::class, function (RetiredMediaPathCleanupJob $job) use ($oldPath): bool {
            $this->assertSame('public', $job->disk);
            $this->assertSame($oldPath, $job->path);
            $this->assertNotNull($job->eligibleAt);
            $this->assertSame(
                now()->addDays(RetiredMediaPathCleanupJob::REPLACE_RETENTION_DAYS)->format('Y-m-d'),
                CarbonImmutable::parse($job->eligibleAt)->format('Y-m-d'),
            );

            return true;
        });
    }

    #[Test]
    public function same_bytes_replace_is_idempotent_and_does_not_queue_cleanup(): void
    {
        Storage::fake('public');
        Queue::fake();
        $file = UploadedFile::fake()->image('same.png', 640, 480);
        $asset = app(OriginalImageIngestService::class)->ingestStandalone($this->actor, $this->workspace, $file);
        $path = (string) $asset->storage_path;

        $result = app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $asset,
            $file,
        );

        $this->assertFalse($result->replaced);
        $this->assertSame($path, (string) $asset->fresh()->storage_path);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function replace_fails_closed_on_hash_collision_with_another_original(): void
    {
        Storage::fake('public');
        Queue::fake();
        $target = $this->managedAsset('target.png', 400, 400);
        $collisionFile = UploadedFile::fake()->image('collision.png', 500, 500);
        $prepared = app(OriginalImageIngestService::class)->prepare($collisionFile);

        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/collision.png',
            'content_sha256' => $prepared->sha256,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(MediaAssetLifecycleException::class);
        $this->expectExceptionMessage('вже існує');

        app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $target,
            $collisionFile,
        );
    }

    #[Test]
    public function replace_fails_closed_on_hash_collision_with_derivative(): void
    {
        Storage::fake('public');
        Queue::fake();
        $target = $this->managedAsset('target.png', 400, 400);
        $collisionFile = UploadedFile::fake()->image('collision.png', 500, 500);
        $prepared = app(OriginalImageIngestService::class)->prepare($collisionFile);
        $parent = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/parent.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $parent->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derivative.png',
            'content_sha256' => $prepared->sha256,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(MediaAssetLifecycleException::class);
        $this->expectExceptionMessage('вже існує');

        app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $target,
            $collisionFile,
        );
    }

    #[Test]
    public function derivative_target_and_original_with_derivatives_fail_closed(): void
    {
        Storage::fake('public');
        $original = $this->managedAsset('parent.png', 700, 500);
        $derivative = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $original->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derived.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $service = app(MediaAssetLifecycleService::class);

        try {
            $service->replaceOriginal(
                $this->actor,
                $this->workspace,
                $original,
                UploadedFile::fake()->image('replacement.png', 800, 600),
            );
            $this->fail('Original with derivatives must not be replaced.');
        } catch (MediaAssetLifecycleException $e) {
            $this->assertStringContainsString('похідних версій', $e->getMessage());
        }

        try {
            $service->deleteUnusedOriginal($this->actor, $this->workspace, $derivative);
            $this->fail('Direct derivative delete must fail closed.');
        } catch (MediaAssetLifecycleException $e) {
            $this->assertStringContainsString('Original asset', $e->getMessage());
        }

        $this->assertDatabaseHas('media_assets', ['id' => $original->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $derivative->id]);
    }

    #[Test]
    public function external_original_becomes_managed_on_same_identity(): void
    {
        Storage::fake('public');
        $external = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/old.jpg',
            'original_filename' => 'old.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $id = (string) $external->id;

        app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $external,
            UploadedFile::fake()->image('managed.png', 1000, 800),
        );

        $fresh = $external->fresh();
        $this->assertSame($id, (string) $fresh->id);
        $this->assertNull($fresh->source_url);
        $this->assertSame('public', $fresh->storage_disk);
        Storage::disk('public')->assertExists((string) $fresh->storage_path);
    }

    #[Test]
    public function guarded_delete_blocks_every_reference_kind_with_fresh_counts(): void
    {
        Storage::fake('public');
        $asset = $this->managedAsset('used.png', 600, 400);
        $this->attachEveryUsage($asset);
        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $asset->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/child.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        try {
            app(MediaAssetLifecycleService::class)->deleteUnusedOriginal($this->actor, $this->workspace, $asset);
            $this->fail('Used asset must not be deleted.');
        } catch (MediaAssetLifecycleException $e) {
            $this->assertSame([
                'products' => 1,
                'variants' => 1,
                'brands' => 1,
                'derivatives' => 1,
            ], $e->usageCounts);
        }

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    #[Test]
    public function unused_managed_delete_commits_before_immediate_cleanup_job(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('unused.png', 600, 400);
        $path = (string) $asset->storage_path;

        app(MediaAssetLifecycleService::class)->deleteUnusedOriginal($this->actor, $this->workspace, $asset);

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($path);
        Queue::assertPushed(RetiredMediaPathCleanupJob::class, fn (RetiredMediaPathCleanupJob $job): bool =>
            $job->path === $path && $job->eligibleAt === null
        );

        (new RetiredMediaPathCleanupJob('public', $path))->handle();
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function unused_external_delete_never_mutates_filesystem_or_queues_cleanup(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/unused-external.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        app(MediaAssetLifecycleService::class)->deleteUnusedOriginal($this->actor, $this->workspace, $asset);

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function cross_workspace_lifecycle_and_missing_permission_fail_closed(): void
    {
        Storage::fake('public');
        $asset = $this->managedAsset('local.png', 500, 500);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign lifecycle']);
        $service = app(MediaAssetLifecycleService::class);

        try {
            $service->replaceOriginal(
                $this->actor,
                $foreignWorkspace,
                $asset,
                UploadedFile::fake()->image('foreign.png', 500, 500),
            );
            $this->fail('Cross-workspace replacement must fail closed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $viewer = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeWorkspaceMembership($this->workspace, $viewer);

        $this->expectException(AuthorizationException::class);
        $service->deleteUnusedOriginal($viewer, $this->workspace, $asset);
    }

    #[Test]
    public function cleanup_job_respects_retention_and_never_deletes_a_referenced_path(): void
    {
        Storage::fake('public');
        $path = 'media/originals/'.$this->workspace->id.'/retired.png';
        Storage::disk('public')->put($path, 'retired');

        (new RetiredMediaPathCleanupJob(
            'public',
            $path,
            now()->addDays(RetiredMediaPathCleanupJob::REPLACE_RETENTION_DAYS)->toIso8601String(),
        ))->handle();
        Storage::disk('public')->assertExists($path);

        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        (new RetiredMediaPathCleanupJob('public', $path, now()->subMinute()->toIso8601String()))->handle();
        Storage::disk('public')->assertExists($path);

        $asset->delete();
        (new RetiredMediaPathCleanupJob('public', $path, now()->subMinute()->toIso8601String()))->handle();
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function orphan_recovery_catches_lost_cleanup_after_grace_without_touching_fresh_or_referenced_files(): void
    {
        Storage::fake('public');
        $oldOrphan = 'media/originals/'.$this->workspace->id.'/lost-job.png';
        $freshOrphan = 'media/originals/'.$this->workspace->id.'/fresh-orphan.png';
        $referenced = 'media/originals/'.$this->workspace->id.'/referenced.png';
        Storage::disk('public')->put($oldOrphan, 'old');
        Storage::disk('public')->put($freshOrphan, 'fresh');
        Storage::disk('public')->put($referenced, 'referenced');
        touch(Storage::disk('public')->path($oldOrphan), now()->subDays(15)->getTimestamp());
        touch(Storage::disk('public')->path($referenced), now()->subDays(15)->getTimestamp());

        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => $referenced,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->artisan('media:cleanup-orphaned-originals')->assertSuccessful();
        Storage::disk('public')->assertExists($oldOrphan);

        $this->artisan('media:cleanup-orphaned-originals', ['--delete' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing($oldOrphan);
        Storage::disk('public')->assertExists($freshOrphan);
        Storage::disk('public')->assertExists($referenced);
    }

    #[Test]
    public function full_asset_page_exposes_lifecycle_actions_with_fresh_usage_impact_and_viewer_cannot_mutate(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('ui-old.png', 600, 400);
        [$productMedia] = $this->attachEveryUsage($asset);

        $component = Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertActionVisible('replace_asset')
            ->assertActionVisible('delete_asset')
            ->mountAction('replace_asset')
            ->assertMountedActionModalSee('Товарів: 1')
            ->assertMountedActionModalSee('Варіантів: 1')
            ->assertMountedActionModalSee('Брендів: 1')
            ->unmountAction()
            ->callAction('replace_asset', [
                'file' => UploadedFile::fake()->image('ui-new.png', 800, 600),
            ])
            ->assertNotified();

        $this->assertSame((string) $asset->id, (string) $productMedia->fresh()->media_asset_id);

        $component
            ->callAction('delete_asset')
            ->assertNotified();
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);

        $viewer = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeWorkspaceMembership($this->workspace, $viewer);

        Livewire::actingAs($viewer)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertActionHidden('replace_asset')
            ->assertActionHidden('delete_asset');
    }

    private function managedAsset(string $name, int $width, int $height): MediaAsset
    {
        return app(OriginalImageIngestService::class)->ingestStandalone(
            $this->actor,
            $this->workspace,
            UploadedFile::fake()->image($name, $width, $height),
        );
    }

    /** @return array{0:ProductMedia,1:VariantMedia,2:Brand} */
    private function attachEveryUsage(MediaAsset $asset): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => 'ASSET-LIFE-'.Str::lower(Str::random(8)),
            'name' => 'Asset lifecycle product',
            'images' => [],
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'ASSET-LIFE-V-'.Str::lower(Str::random(8)),
            'attributes' => [],
            'is_active' => true,
        ]);
        $productMedia = ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        $variantMedia = VariantMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        $brand = Brand::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Lifecycle Brand '.Str::random(6),
            'logo_media_asset_id' => $asset->id,
            'is_active' => true,
        ]);

        return [$productMedia, $variantMedia, $brand];
    }
}