<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\MediaAssetResource\Pages\EditMediaAsset;
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
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
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
        $this->expectExceptionMessage('вже є в Assets');

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
        $this->expectExceptionMessage('вже є в Assets');

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
    public function manager_full_asset_card_uses_native_edit_record_with_inline_replacement_and_close(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('close-page.png', 600, 400);
        $fallback = Js::from(MediaAssetResource::getUrl('index'));
        $closeJs = "window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100);";

        Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertSee('Поточне зображення')
            ->assertSee('Нове зображення')
            ->assertSee('Розмір')
            ->assertSee('Мегапікселі')
            ->assertSee('Вага')
            ->assertSee('Формат')
            ->assertSee('Коментар')
            ->assertActionVisible('close_page')
            ->assertActionVisible('delete_asset')
            ->assertSeeHtml('setUpUnsavedDataChangesAlert')
            ->callAction('close_page')
            ->assertJs($closeJs);

        Livewire::actingAs($this->actor)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertActionDoesNotExist('replace_asset')
            ->assertDontSee('Нове зображення');
    }

    #[Test]
    public function lifecycle_conflict_in_asset_editor_is_a_merchant_warning_and_leaves_asset_unchanged(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('blocked.png', 600, 400);
        $oldHash = (string) $asset->content_sha256;
        $oldPath = (string) $asset->storage_path;
        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $asset->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/blocked-child.png',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm([
                'replacement_upload' => UploadedFile::fake()->image('replacement.png', 800, 600),
            ])
            ->call('save')
            ->assertSet('replacementBusinessWarning', fn (?string $value): bool => str_contains((string) $value, 'похідних версій'))
            ->assertSee('Зображення не змінено')
            ->assertSee('похідних версій');

        $fresh = $asset->fresh();
        $this->assertSame($oldHash, (string) $fresh->content_sha256);
        $this->assertSame($oldPath, (string) $fresh->storage_path);
    }

    #[Test]
    public function duplicate_asset_replacement_is_explained_as_a_warning_without_mutating_identity(): void
    {
        Storage::fake('public');
        Queue::fake();
        $target = $this->managedAsset('target-ui.png', 600, 400);
        $oldHash = (string) $target->content_sha256;
        $collisionFile = UploadedFile::fake()->image('already-in-assets.png', 500, 500);
        $prepared = app(OriginalImageIngestService::class)->prepare($collisionFile);

        MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/already-in-assets.png',
            'content_sha256' => $prepared->sha256,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $target->getRouteKey()])
            ->fillForm(['replacement_upload' => $collisionFile])
            ->call('save')
            ->assertSet('replacementBusinessWarning', 'Це зображення вже є в Assets. Щоб не створювати дубль, поточний Asset не змінено. Оберіть інший файл.')
            ->assertSee('Зображення не змінено')
            ->assertSee('Це зображення вже є в Assets');

        $this->assertSame($oldHash, (string) $target->fresh()->content_sha256);
        $this->assertDatabaseCount('media_assets', 2);
    }

    #[Test]
    public function native_asset_edit_save_replaces_same_identity_then_close_has_no_second_confirmation(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('ui-old.png', 600, 400);
        [$productMedia] = $this->attachEveryUsage($asset);
        $fallback = Js::from(MediaAssetResource::getUrl('index'));
        $closeJs = "window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100);";

        $component = Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm([
                'replacement_upload' => UploadedFile::fake()->image('ui-new.png', 800, 600),
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Зображення замінено');

        $fresh = $asset->fresh();
        $this->assertSame('ui-new.png', $fresh->original_filename);
        $this->assertSame((string) $asset->id, (string) $productMedia->fresh()->media_asset_id);
        $this->assertNull(data_get($component->get('data'), 'replacement_upload'));

        $component
            ->callAction('close_page')
            ->assertJs($closeJs);

        $component
            ->callAction('delete_asset')
            ->assertNotified();
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    #[Test]
    public function asset_internal_note_can_be_saved_without_replacing_the_file(): void
    {
        Storage::fake('public');
        $asset = $this->managedAsset('note-only.png', 600, 400);
        $oldHash = (string) $asset->content_sha256;
        $oldPath = (string) $asset->storage_path;

        Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['internal_note' => '  Внутрішня нотатка для команди  '])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Asset збережено');

        $fresh = $asset->fresh();
        $this->assertSame('Внутрішня нотатка для команди', $fresh->internal_note);
        $this->assertSame($oldHash, (string) $fresh->content_sha256);
        $this->assertSame($oldPath, (string) $fresh->storage_path);
    }

    #[Test]
    public function asset_replace_preserves_and_updates_internal_note_on_same_identity(): void
    {
        Storage::fake('public');
        Queue::fake();
        $asset = $this->managedAsset('note-replace-old.png', 600, 400);
        $asset->update(['internal_note' => 'Стара нотатка']);
        $id = (string) $asset->id;

        Livewire::actingAs($this->actor)
            ->test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm([
                'replacement_upload' => UploadedFile::fake()->image('note-replace-new.png', 800, 600),
                'internal_note' => 'Оновлена нотатка',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Зображення замінено');

        $fresh = $asset->fresh();
        $this->assertSame($id, (string) $fresh->id);
        $this->assertSame('note-replace-new.png', $fresh->original_filename);
        $this->assertSame('Оновлена нотатка', $fresh->internal_note);
    }

    #[Test]
    public function viewer_keeps_read_only_asset_full_card_and_cannot_open_editor(): void
    {
        Storage::fake('public');
        $asset = $this->managedAsset('viewer.png', 600, 400);
        $viewer = User::factory()->create(['role' => UserRole::Admin]);
        $this->makeWorkspaceMembership($this->workspace, $viewer);

        Livewire::actingAs($viewer)
            ->test(ViewMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertActionHidden('delete_asset')
            ->assertActionVisible('close_page');

        $this->assertFalse(MediaAssetResource::canEdit($asset));
        $this->actingAs($viewer)
            ->get(MediaAssetResource::getUrl('edit', ['record' => $asset]))
            ->assertForbidden();
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
