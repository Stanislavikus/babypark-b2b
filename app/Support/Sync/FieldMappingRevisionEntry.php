<?php

namespace App\Support\Sync;

final readonly class FieldMappingRevisionEntry
{
    public function __construct(
        public string $fieldBindingId,
        public string $externalFieldKey,
    ) {}

    /**
     * @return array{field_binding_id: string, external_field_key: string}
     */
    public function toRevisionArray(): array
    {
        return [
            'field_binding_id' => $this->fieldBindingId,
            'external_field_key' => $this->externalFieldKey,
        ];
    }
}
