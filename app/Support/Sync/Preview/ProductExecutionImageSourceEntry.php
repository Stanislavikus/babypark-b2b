<?php

namespace App\Support\Sync\Preview;

final readonly class ProductExecutionImageSourceEntry
{
    public function __construct(
        public int $declarationIndex,
        public ?string $sourceReference,
        public bool $isMalformed = false,
    ) {}

    public function isPrimary(): bool
    {
        return $this->declarationIndex === 0;
    }
}
