<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;

final readonly class AdobeProductRemoteMediaMetadataIndex
{
    /**
     * @param  list<AdobeProductRemoteMediaMetadataEntry>  $entries
     */
    public function __construct(
        public AdobeProductAppliedStateKnowledge $trustState,
        public array $entries,
        public string $reasonCode = '',
    ) {}

    public static function trusted(array $entries): self
    {
        return new self(AdobeProductAppliedStateKnowledge::KnownApplied, $entries);
    }

    public static function untrusted(string $reasonCode): self
    {
        return new self(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, [], $reasonCode);
    }

    public function isTrusted(): bool
    {
        return $this->trustState === AdobeProductAppliedStateKnowledge::KnownApplied;
    }
}
