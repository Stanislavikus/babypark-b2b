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
use App\Services\Media\RetiredMediaPathRegistry;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Workspace\WorkspacePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

        Storage::fake('local');
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
            $this->assertNotNull($job->markerPath);
            $this->assertTrue($job->afterCommit);
            $this->assertSame(
                now()->addDays(RetiredMediaPathCleanupJob::REPLACE_RETENTION_DAYS)->format('Y-m-d'),
                CarbonImmutable::parse($job->eligibleAt)->format('Y-m-d'),
            );
            Storage::disk('local')->assertExists($job->markerPath);

            return true;
        });
    }

    #[Test]
    public function outer_transaction_rollback_restores_replaced_asset_and_cleans_new_file_and_marker(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('rollback-old.png', 600, 400);
        $oldPath = (string) $asset->storage_path;
        $oldHash = (string) $asset->content_sha256;
        $newPath = null;

        DB::beginTransaction();

        try {
            $result = app(MediaAssetLifecycleService::class)->replaceOriginal(
                $this->actor,
                $this->workspace,
                $asset,
                UploadedFile::fake()->image('rollback-new.png', 900, 700),
            );

            $this->assertTrue($result->replaced);
            $inside = $asset->fresh();
            $newPath = (string) $inside->storage_path;
            $this->assertNotSame($oldPath, $newPath);
            Storage::disk('public')->assertExists($oldPath);
            Storage::disk('public')->assertExists($newPath);
            $this->assertCount(1, Storage::disk('local')->allFiles('media-retirement/v1'));
        } finally {
            DB::rollBack();
        }

        $fresh = $asset->fresh();
        $this->assertSame($oldPath, (string) $fresh->storage_path);
        $this->assertSame($oldHash, (string) $fresh->content_sha256);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertNotNull($newPath);
        Storage::disk('public')->assertMissing($newPath);
        $this->assertSame([], Storage::disk('local')->allFiles('media-retirement/v1'));
    }

    #[Test]
    public function outer_transaction_rollback_restores_deleted_asset_and_removes_cleanup_marker(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('delete-rollback.png', 600, 400);
        $path = (string) $asset->storage_path;

        DB::beginTransaction();

        try {
            app(MediaAssetLifecycleService::class)->deleteUnusedOriginal(
                $this->actor,
                $this->workspace,
                $asset,
            );

            $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
            Storage::disk('public')->assertExists($path);
            $this->assertCount(1, Storage::disk('local')->allFiles('media-retirement/v1'));
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([], Storage::disk('local')->allFiles('media-retirement/v1'));
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
        $cleanupJob = null;

        app(MediaAssetLifecycleService::class)->deleteUnusedOriginal($this->actor, $this->workspace, $asset);

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($path);
        Queue::assertPushed(RetiredMediaPathCleanupJob::class, function (RetiredMediaPathCleanupJob $job) use ($path, &$cleanupJob): bool {
            $cleanupJob = $job;

            return $job->path === $path
                && $job->eligibleAt !== null
                && $job->markerPath !== null
                && $job->afterCommit === true;
        });
        $this->assertInstanceOf(RetiredMediaPathCleanupJob::class, $cleanupJob);
        $this->assertNotNull($cleanupJob->markerPath);
        Storage::disk('local')->assertExists($cleanupJob->markerPath);

        $cleanupJob->handle(app(RetiredMediaPathRegistry::class));

        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertMissing($cleanupJob->markerPath);
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
        ))->handle(app(RetiredMediaPathRegistry::class));
        Storage::disk('public')->assertExists($path);

        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        (new RetiredMediaPathCleanupJob('public', $path, now()->subMinute()->toIso8601String()))->handle(app(RetiredMediaPathRegistry::class));
        Storage::disk('public')->assertExists($path);

        $asset->delete();
        (new RetiredMediaPathCleanupJob('public', $path, now()->subMinute()->toIso8601String()))->handle(app(RetiredMediaPathRegistry::class));
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function lost_replace_cleanup_job_cannot_bypass_grace_even_when_old_file_mtime_is_old(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->travelTo('2026-10-09 08:00:00');
        $asset = $this->managedAsset('old-for-grace.png', 600, 400);
        $oldPath = (string) $asset->storage_path;
        touch(Storage::disk('public')->path($oldPath), now()->subDays(60)->getTimestamp());

        app(MediaAssetLifecycleService::class)->replaceOriginal(
            $this->actor,
            $this->workspace,
            $asset,
            UploadedFile::fake()->image('replacement-for-grace.png', 900, 700),
        );

        $markers = Storage::disk('local')->allFiles('media-retirement/v1');
        $this->assertCount(1, $markers);
        Storage::disk('public')->assertExists($oldPath);

        // Simulate a cleared/lost delayed queue job: only the durable cleanup marker remains.
        $this->artisan('media:cleanup-orphaned-originals', ['--delete' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('local')->assertExists($markers[0]);

        $this->travel(13)->days();
        $this->artisan('media:cleanup-orphaned-originals', ['--delete' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($oldPath);

        $this->travel(2)->days();
        $this->artisan('media:cleanup-orphaned-originals', ['--delete' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('local')->assertMissing($markers[0]);
    }

    #[Test]
    public function replace_failure_closes_confirmation_and_surfaces_inline_file_error(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('blocked.png', 600, 400);
        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $asset->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/blocked-child.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
        $replaceAction = TestAction::make('replace_asset')
            ->schemaComponent('replacement_actions', 'replacementForm');

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm([
                'file' => [UploadedFile::fake()->image('replacement.png', 800, 600)],
            ], 'replacementForm')
            ->mountAction($replaceAction)
            ->callMountedAction()
            ->assertActionNotMounted()
            ->assertHasErrors(['replacementData.file']);

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    #[Test]
    public function full_asset_page_exposes_lifecycle_actions_with_fresh_usage_impact_and_viewer_cannot_mutate(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('ui-old.png', 600, 400);
        [$productMedia] = $this->attachEveryUsage($asset);

        $replaceAction = TestAction::make('replace_asset')
            ->schemaComponent('replacement_actions', 'replacementForm');

        $component = Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertFormExists('replacementForm')
            ->assertFormFieldExists('file', 'replacementForm')
            ->assertSee('Замінити зображення')
            ->assertActionVisible($replaceAction)
            ->assertActionVisible('delete_asset')
            ->fillForm([
                'file' => [UploadedFile::fake()->image('ui-new.png', 800, 600)],
            ], 'replacementForm')
            ->mountAction($replaceAction)
            ->assertMountedActionModalSee('Товарів: 1')
            ->assertMountedActionModalSee('Варіантів: 1')
            ->assertMountedActionModalSee('Брендів: 1')
            ->callMountedAction()
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
            ->assertDontSee('Замінити зображення')
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
