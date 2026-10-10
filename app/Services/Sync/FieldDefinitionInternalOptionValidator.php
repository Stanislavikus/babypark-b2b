<?php

namespace App\Services\Sync;

use App\Enums\AttributeDataType;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Support\Sync\Exceptions\FieldMappingValidationException;

final class FieldDefinitionInternalOptionValidator
{
    public function validate(FieldMapping $mapping, string $internalOptionKey): void
    {
        $mapping->loadMissing('fieldBinding.fieldDefinition');

        $definition = $mapping->fieldBinding?->fieldDefinition;

        if ($definition === null) {
            throw FieldMappingValidationException::mappingNotFound($mapping->sync_configuration_id, $mapping->id);
        }

        if ($definition->data_type !== AttributeDataType::Select) {
            throw FieldMappingValidationException::invalidInternalOptionKey(
                $internalOptionKey,
                $definition->id,
            );
        }

        if ($definition->is_multi_value) {
            throw FieldMappingValidationException::invalidInternalOptionKey(
                $internalOptionKey,
                $definition->id,
            );
        }

        $allowedOptionKeys = $this->allowedOptionKeys($definition);

        if ($allowedOptionKeys === []) {
            throw FieldMappingValidationException::invalidInternalOptionKey(
                $internalOptionKey,
                $definition->id,
            );
        }

        if (! in_array($internalOptionKey, $allowedOptionKeys, true)) {
            throw FieldMappingValidationException::invalidInternalOptionKey($internalOptionKey, $definition->id);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedOptionKeys(FieldDefinition $definition): array
    {
        $validationRules = $definition->validation_rules;

        if (! is_array($validationRules)) {
            return [];
        }

        $options = $validationRules['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $keys = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $code = $option['code'] ?? null;

            if (is_string($code) && $code !== '') {
                $keys[] = $code;
            }
        }

        return $keys;
    }
}
