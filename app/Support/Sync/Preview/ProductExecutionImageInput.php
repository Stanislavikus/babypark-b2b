<?php

namespace App\Support\Sync\Preview;

final readonly class ProductExecutionImageInput
{
    /**
     * @param  list<ProductExecutionImageSourceEntry>  $entries
     */
    public function __construct(
        public ProductExecutionImageStructuralState $structuralState,
        public array $entries,
    ) {}

    public function hasEntries(): bool
    {
        return $this->entries !== [];
    }
}
