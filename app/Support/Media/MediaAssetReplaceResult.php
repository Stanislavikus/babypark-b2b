<?php

namespace App\Support\Media;

use App\Models\MediaAsset;

final readonly class MediaAssetReplaceResult
{
    public function __construct(
        public MediaAsset $asset,
        public bool $replaced,
    ) {}
}
