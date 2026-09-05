<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final readonly class AdobeProductMediaDesiredEntry
{
    public function __construct(
        public int $declarationIndex,
        public AdobeProductMediaRole $role,
        public string $label,
        public int $position,
        public string $contentSha256,
        public string $mimeType,
        public string $filename,
        public string $rawBytes,
    ) {}

    /**
     * @return list<string>
     */
    public function magentoTypes(): array
    {
        return $this->role->magentoTypes();
    }
}
