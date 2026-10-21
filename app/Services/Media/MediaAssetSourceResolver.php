<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

final class MediaAssetSourceResolver
{
    public function sourceReference(?MediaAsset $asset): ?string
    {
        if (! $asset instanceof MediaAsset || ! $asset->isOriginal()) {
            return null;
        }

        if (filled($asset->source_url)) {
            return trim((string) $asset->source_url);
        }

        if (! filled($asset->storage_disk) || ! filled($asset->storage_path)) {
            return null;
        }

        $url = Storage::disk((string) $asset->storage_disk)->url((string) $asset->storage_path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    public function sourceKind(MediaAsset $asset): string
    {
        if (filled($asset->source_url)) {
            return 'external';
        }

        if (filled($asset->storage_disk) && filled($asset->storage_path)) {
            return 'managed';
        }

        return 'missing';
    }
}
