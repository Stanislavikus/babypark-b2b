<?php

namespace App\Support\Sync\FieldMappingReadModel;

use App\Enums\FieldObjectType;

final readonly class FieldMappingInternalRow
{
    public function __construct(
        public string $fieldBindingId,
        public string $internalFieldCode,
        public FieldObjectType $objectType,
        public string $label,
        public ?string $existingExternalFieldKey,
        public ?string $suggestedExternalFieldKey,
        public bool $needsAttention,
    ) {}
}
