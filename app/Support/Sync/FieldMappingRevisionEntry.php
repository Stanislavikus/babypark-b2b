<?php

namespace App\Support\Sync;

final readonly class FieldMappingRevisionEntry
{
    /**
     * @param  list<FieldOptionMappingRevisionEntry>  $optionMappings
     */
    public function __construct(
        public string $fieldBindingId,
        public string $externalFieldKey,
        public array $optionMappings = [],
    ) {}

    /**
     * @return array{
     *     field_binding_id: string,
     *     external_field_key: string,
     *     option_mappings: list<array{internal_option_key: string, external_option_value: string}>
     * }
     */
    public function toRevisionArray(): array
    {
        return [
            'field_binding_id' => $this->fieldBindingId,
            'external_field_key' => $this->externalFieldKey,
            'option_mappings' => array_map(
                static fn (FieldOptionMappingRevisionEntry $entry): array => $entry->toRevisionArray(),
                $this->optionMappings,
            ),
        ];
    }
}
