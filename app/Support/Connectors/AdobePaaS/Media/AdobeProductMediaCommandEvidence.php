<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;

final readonly class AdobeProductMediaCommandEvidence
{
    public function __construct(
        public int $declarationIndex,
        public AdobeProductMediaRole $role,
        public AdobeProductAppliedStateKnowledge $appliedStateKnowledge,
        public string $reasonCode,
        public ?string $mimeType = null,
        public ?int $mediaEntryId = null,
        public int $consequentialWriteAttempts = 0,
        public int $reconciliationGetAttempts = 0,
        public ?string $contentSha256Prefix = null,
    ) {}
}
