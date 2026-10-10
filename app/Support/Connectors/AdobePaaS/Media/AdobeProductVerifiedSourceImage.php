<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final readonly class AdobeProductVerifiedSourceImage
{
    public function __construct(
        public int $declarationIndex,
        public AdobeProductMediaRole $role,
        public string $contentSha256,
        public string $mimeType,
        public string $filename,
        public string $rawBytes,
    ) {}

    public function position(): int
    {
        return $this->declarationIndex + 1;
    }
}
