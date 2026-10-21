<?php

namespace App\Support\Media;

use App\Models\MediaAsset;

final readonly class OriginalImageIngestResult
{
    /**
     * @param  array{disk:string,path:string}|null  $newStoredPath
     */
    public function __construct(
        public MediaAsset $asset,
        public ?array $newStoredPath,
    ) {}

    public function createdManagedOriginal(): bool
    {
        return $this->newStoredPath !== null;
    }
}
