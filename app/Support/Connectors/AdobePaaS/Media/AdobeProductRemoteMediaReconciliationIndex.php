<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final readonly class AdobeProductRemoteMediaReconciliationIndex
{
    /**
     * @param  array<string, list<AdobeProductRemoteMediaMetadataEntry>>  $entriesByContentHash
     * @param  array<string, array{entryId: int, contentSha256: string}>  $imageFilenameIndex
     */
    public function __construct(
        public array $entriesByContentHash,
        public array $imageFilenameIndex,
    ) {}
}
