<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;

final readonly class AdobeProductRemoteMediaContentEntry
{
    public function __construct(
        public int $entryId,
        public string $contentSha256,
        public string $mimeType,
        public string $filename,
        public AdobeProductAppliedStateKnowledge $trustState,
        public string $reasonCode = '',
    ) {}

    public function isTrusted(): bool
    {
        return $this->trustState === AdobeProductAppliedStateKnowledge::KnownApplied;
    }

    public static function untrusted(int $entryId, string $reasonCode): self
    {
        return new self(
            entryId: $entryId,
            contentSha256: '',
            mimeType: '',
            filename: '',
            trustState: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
        );
    }
}
