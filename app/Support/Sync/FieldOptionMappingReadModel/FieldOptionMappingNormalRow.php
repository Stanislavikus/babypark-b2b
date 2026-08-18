<?php

namespace App\Support\Sync\FieldOptionMappingReadModel;

final readonly class FieldOptionMappingNormalRow
{
    public function __construct(
        public string $internalOptionKey,
        public string $internalLabel,
        public ?string $externalOptionValue,
        public ?string $externalLabel,
        public string $semanticState,
        public ?string $existingExternalOptionValue,
    ) {}
}
