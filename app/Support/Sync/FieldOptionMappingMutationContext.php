<?php

namespace App\Support\Sync;

final readonly class FieldOptionMappingMutationContext
{
    public function __construct(
        public string $configurationRevision,
        public string $fieldMappingId,
        public string $externalFieldKey,
        public string $internalOptionKey,
        public ?string $externalOptionValue = null,
        public ?string $existingOptionMappingId = null,
        public ?string $existingExternalOptionValue = null,
    ) {}
}
