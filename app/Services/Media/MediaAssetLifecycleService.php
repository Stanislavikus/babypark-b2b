<?php

namespace App\Services\Media;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Jobs\Media\RetiredMediaPathCleanupJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Media\MediaAssetReplaceResult;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MediaAssetLifecycleService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly OriginalImageIngestService $ingest,
        private readonly MediaAssetLibraryReadService $readService,
    ) {}

    public function replaceOriginal(
        User $actor,
        Workspace $workspace,
        MediaAsset $asset,
        UploadedFile $file,
    ): MediaAssetReplaceResult {
        $prepared = $this->ingest->prepare($file);
        $newStoredPath = null;

        try {
            return DB::transaction(function () use (
                $actor,
                $workspace,
                $asset,
                $prepared,
                &$newStoredPath,
            ): MediaAssetReplaceResult {
                $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
                $lockedAsset = $this->lockAsset($lockedWorkspace, $asset);
                $this->assertLifecycleOriginal($lockedAsset);

                $usage = $this->readService->freshUsageCounts($lockedAsset);

                if ($usage['derivatives'] > 0) {
                    throw MediaAssetLifecycleException::derivativesBlockReplace($usage['derivatives']);
                }

                if (is_string($lockedAsset->content_sha256)
                    && hash_equals($lockedAsset->content_sha256, $prepared->sha256)
                ) {
                    return new MediaAssetReplaceResult($lockedAsset, false);
                }

                $collision = MediaAsset::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('content_sha256', $prepared->sha256)
                    ->whereKeyNot($lockedAsset->id)
                    ->lockForUpdate()
                    ->first();

                if ($collision instanceof MediaAsset) {
                    throw MediaAssetLifecycleException::duplicateContent();
                }

                $storagePath = $prepared->file->storeAs(
                    'media/originals/'.$lockedWorkspace->id,
                    (string) Str::uuid().'.'.$prepared->extension,
                    ['disk' => 'public'],
                );

                if (! is_string($storagePath) || $storagePath === '') {
                    throw MediaAssetLifecycleException::storageFailed();
                }

                $newStoredPath = ['disk' => 'public', 'path' => $storagePath];
                $retiredStoredPath = $this->storedPath($lockedAsset);

                $lockedAsset->forceFill([
                    'asset_type' => MediaAssetType::Image,
                    'storage_disk' => 'public',
                    'storage_path' => $storagePath,
                    'source_url' => null,
                    'original_filename' => $this->ingest->safeOriginalFilename($prepared),
                    'mime_type' => $prepared->mimeType,
                    'byte_size' => $prepared->byteSize,
                    'content_sha256' => $prepared->sha256,
                    'width_px' => $prepared->widthPx,
                    'height_px' => $prepared->heightPx,
                    'diagnosis_status' => MediaDiagnosisStatus::Ready,
                    'diagnosis_json' => [
                        'native_width_px' => $prepared->widthPx,
                        'native_height_px' => $prepared->heightPx,
                        'mime_type' => $prepared->mimeType,
                        'byte_size' => $prepared->byteSize,
                    ],
                    'provenance_json' => [
                        'kind' => 'merchant_replace',
                        'actor_user_id' => (string) $actor->id,
                    ],
                ])->save();

                if ($retiredStoredPath !== null
                    && ($retiredStoredPath['disk'] !== $newStoredPath['disk']
                        || $retiredStoredPath['path'] !== $newStoredPath['path'])
                ) {
                    $eligibleAt = now()->addDays(RetiredMediaPathCleanupJob::REPLACE_RETENTION_DAYS);

                    RetiredMediaPathCleanupJob::dispatch(
                        $retiredStoredPath['disk'],
                        $retiredStoredPath['path'],
                        $eligibleAt->toIso8601String(),
                    )
                        ->delay($eligibleAt)
                        ->afterCommit();
                }

                return new MediaAssetReplaceResult($lockedAsset->refresh(), true);
            });
        } catch (Throwable $e) {
            if ($newStoredPath !== null) {
                $fresh = MediaAsset::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($asset->id)
                    ->first();

                $committed = $fresh instanceof MediaAsset
                    && (string) $fresh->storage_disk === $newStoredPath['disk']
                    && (string) $fresh->storage_path === $newStoredPath['path']
                    && is_string($fresh->content_sha256)
                    && hash_equals($fresh->content_sha256, $prepared->sha256);

                if ($committed) {
                    report($e);

                    return new MediaAssetReplaceResult($fresh, true);
                }

                Storage::disk($newStoredPath['disk'])->delete($newStoredPath['path']);
            }

            if ($e instanceof QueryException) {
                $collisionExists = MediaAsset::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('content_sha256', $prepared->sha256)
                    ->whereKeyNot($asset->id)
                    ->exists();

                if ($collisionExists) {
                    throw MediaAssetLifecycleException::duplicateContent();
                }
            }

            throw $e;
        }
    }

    public function deleteUnusedOriginal(
        User $actor,
        Workspace $workspace,
        MediaAsset $asset,
    ): void {
        try {
            DB::transaction(function () use ($actor, $workspace, $asset): void {
                $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
                $lockedAsset = $this->lockAsset($lockedWorkspace, $asset);
                $this->assertLifecycleOriginal($lockedAsset);
                $usage = $this->readService->freshUsageCounts($lockedAsset);

                if (array_sum($usage) > 0) {
                    throw MediaAssetLifecycleException::inUse($usage);
                }

                $retiredStoredPath = $this->storedPath($lockedAsset);
                $lockedAsset->delete();

                if ($retiredStoredPath !== null) {
                    RetiredMediaPathCleanupJob::dispatch(
                        $retiredStoredPath['disk'],
                        $retiredStoredPath['path'],
                    )->afterCommit();
                }
            });
        } catch (Throwable $e) {
            $fresh = MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($asset->id)
                ->first();

            if (! $fresh instanceof MediaAsset) {
                report($e);

                return;
            }

            if ($e instanceof QueryException) {
                $usage = $this->readService->freshUsageCounts($fresh);

                if (array_sum($usage) > 0) {
                    throw MediaAssetLifecycleException::inUse($usage);
                }
            }

            throw $e;
        }
    }

    private function lockWorkspaceAndAuthorize(User $actor, Workspace $workspace): Workspace
    {
        $lockedWorkspace = Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $lockedWorkspace;
    }

    private function lockAsset(Workspace $workspace, MediaAsset $asset): MediaAsset
    {
        $lockedAsset = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($asset->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedAsset instanceof MediaAsset) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $lockedAsset;
    }

    private function assertLifecycleOriginal(MediaAsset $asset): void
    {
        if (! $asset->isOriginal()) {
            throw MediaAssetLifecycleException::originalRequired();
        }

        if ($asset->asset_type !== MediaAssetType::Image) {
            throw MediaAssetLifecycleException::imageRequired();
        }
    }

    /**
     * @return array{disk:string,path:string}|null
     */
    private function storedPath(MediaAsset $asset): ?array
    {
        if (! filled($asset->storage_disk) || ! filled($asset->storage_path)) {
            return null;
        }

        return [
            'disk' => (string) $asset->storage_disk,
            'path' => (string) $asset->storage_path,
        ];
    }
}