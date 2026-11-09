<?php

namespace App\Support\Sync\Preview;

use App\Enums\AttributeDataType;
use App\Enums\FieldObjectType;

final readonly class MappedFieldValue
{
    public function __construct(
        public string $fieldBindingId,
        public string $internalCode,
        public FieldObjectType $objectType,
        public AttributeDataType $dataType,
        public bool $isRequired,
        public bool $isMultiValue,
        public mixed $value,
    ) {}
}
