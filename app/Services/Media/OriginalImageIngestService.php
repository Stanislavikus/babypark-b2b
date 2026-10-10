<?php

namespace App\Services\Media;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Media\OriginalImageIngestResult;
use App\Support\Media\PreparedOriginalImage;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class OriginalImageIngestService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_PIXELS = 25_000_000;

    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function prepare(UploadedFile $file): PreparedOriginalImage
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw MediaIngestException::invalidImage();
        }

        $byteSize = filesize($path);

        if (! is_int($byteSize)) {
            throw MediaIngestException::invalidImage();
        }

        if ($byteSize > self::MAX_BYTES) {
            throw MediaIngestException::fileTooLarge();
        }

        $reportedMime = strtolower((string) $file->getMimeType());
        $clientName = strtolower($file->getClientOriginalName());
        $prefix = file_get_contents($path, false, null, 0, 2048);

        if (
            $reportedMime === 'image/svg+xml'
            || str_ends_with($clientName, '.svg')
            || (is_string($prefix) && str_contains(strtolower($prefix), '<svg'))
        ) {
            throw MediaIngestException::svgUnsupported();
        }

        $info = @getimagesize($path);

        if ($info === false || ! isset($info[0], $info[1], $info['mime'])) {
            throw MediaIngestException::invalidImage();
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width <= 0 || $height <= 0 || ($width * $height) > self::MAX_PIXELS) {
            throw MediaIngestException::pixelLimitExceeded();
        }

        $mimeType = strtolower((string) $info['mime']);
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => throw MediaIngestException::invalidImage(),
        };

        $sha256 = hash_file('sha256', $path);

        if (! is_string($sha256) || $sha256 === '') {
            throw MediaIngestException::invalidImage();
        }

        return new PreparedOriginalImage(
            file: $file,
            mimeType: $mimeType,
            extension: $extension,
            byteSize: $byteSize,
            sha256: $sha256,
            widthPx: $width,
            heightPx: $height,
        );
    }

    public function ingestPrepared(
        User $actor,
        Workspace $lockedWorkspace,
        PreparedOriginalImage $prepared,
    ): OriginalImageIngestResult {
        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $asset = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $lockedWorkspace->id)
            ->where('content_sha256', $prepared->sha256)
            ->lockForUpdate()
            ->first();

        if ($asset instanceof MediaAsset) {
            if (! $asset->isOriginal()) {
                throw MediaIngestException::derivativeHashConflict();
            }

            return new OriginalImageIngestResult($asset, null);
        }

        $assetId = (string) Str::uuid();
        $storagePath = $prepared->file->storeAs(
            'media/originals/'.$lockedWorkspace->id,
            $assetId.'.'.$prepared->extension,
            ['disk' => 'public'],
        );

        if (! is_string($storagePath) || $storagePath === '') {
            throw MediaIngestException::storageFailed();
        }

        $stored = ['disk' => 'public', 'path' => $storagePath];

        try {
            $asset = MediaAsset::withoutWorkspaceScope()->create([
                'id' => $assetId,
                'workspace_id' => $lockedWorkspace->id,
                'parent_media_asset_id' => null,
                'asset_type' => MediaAssetType::Image,
                'storage_disk' => 'public',
                'storage_path' => $storagePath,
                'source_url' => null,
                'original_filename' => $this->safeOriginalFilename($prepared),
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
                    'kind' => 'merchant_upload',
                    'actor_user_id' => (string) $actor->id,
                ],
            ]);
        } catch (Throwable $e) {
            Storage::disk($stored['disk'])->delete($stored['path']);

            throw $e;
        }

        return new OriginalImageIngestResult($asset, $stored);
    }

    public function safeOriginalFilename(PreparedOriginalImage $prepared): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $prepared->file->getClientOriginalName());
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return 'upload.'.$prepared->extension;
        }

        return mb_substr($name, 0, 512);
    }

    public function ingestStandalone(
        User $actor,
        Workspace $workspace,
        UploadedFile $file,
    ): MediaAsset {
        return $this->ingestStandaloneMany($actor, $workspace, [$file])[0];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<MediaAsset>
     */
    public function ingestStandaloneMany(
        User $actor,
        Workspace $workspace,
        array $files,
    ): array {
        $prepared = collect($files)
            ->map(fn (UploadedFile $file): PreparedOriginalImage => $this->prepare($file))
            ->all();
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $workspace, $prepared, &$storedPaths): array {
                $lockedWorkspace = Workspace::query()
                    ->whereKey($workspace->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $assets = [];

                foreach ($prepared as $item) {
                    $result = $this->ingestPrepared($actor, $lockedWorkspace, $item);
                    $assets[] = $result->asset;

                    if ($result->newStoredPath !== null) {
                        $storedPaths[] = $result->newStoredPath;
                    }
                }

                return $assets;
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk($storedPath['disk'])->delete($storedPath['path']);
            }

            throw $e;
        }
    }
}
