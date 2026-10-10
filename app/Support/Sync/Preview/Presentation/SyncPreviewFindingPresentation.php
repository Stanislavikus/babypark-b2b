<?php

namespace App\Support\Sync\Preview\Presentation;

final readonly class SyncPreviewFindingPresentation
{
    /**
     * @param  list<SyncPreviewRemediationDestinationPresentation>  $destinations
     */
    public function __construct(
        public string $summary,
        public ?string $fieldContext,
        public ?string $variantContext,
        public array $destinations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'field_context' => $this->fieldContext,
            'variant_context' => $this->variantContext,
            'destinations' => array_map(
                static fn (SyncPreviewRemediationDestinationPresentation $destination): array => $destination->toArray(),
                $this->destinations,
            ),
        ];
    }
}
