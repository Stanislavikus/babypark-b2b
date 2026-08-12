<?php

namespace App\Support\Sync\FieldMappingReadModel;

final readonly class DiscoveredExternalFieldChoice
{
    public function __construct(
        public string $externalFieldKey,
        public string $externalLabel,
        public string $normalizedDataType,
        public bool $isRequired,
        public bool $isMultiValue,
        public bool $isLocalizable,
    ) {}
}
