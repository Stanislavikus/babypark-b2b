<?php

namespace App\Services\Fields;

use App\Enums\AttributeDataType;
use App\Models\FieldDefinition;
use App\Services\Fields\Exceptions\InvalidSelectOptionException;
use App\Services\Fields\Exceptions\MultiValueNotSupportedException;

/**
 * Validates that a single-value string payload is one of the
 * declared stable internal option codes for a Select FieldDefinition.
 *
 * This validator is intentionally FieldDefinition-only — it does NOT
 * take a FieldMapping, SyncConfiguration, or ConnectorAccount, so it
 * can be reused by the GovernedDynamicFieldValueWriter (Product
 * domain) and any other Product-domain caller that needs to verify
 * a value against a definition's declared option catalogue.
 *
 * It is the platform-core single-select option check; the existing
 * App\Services\Sync\FieldDefinitionInternalOptionValidator remains
 * the connector-side (FieldMapping + ConnectorAccount) variant.
 */
final class FieldDefinitionSelectOptionValidator
{
    /**
     * @throws InvalidSelectOptionException When the value is not a declared option.
     * @throws MultiValueNotSupportedException When the definition is multi-value.
     */
    public function assertOptionAllowed(FieldDefinition $definition, string $internalOptionKey): void
    {
        if ($definition->data_type !== AttributeDataType::Select) {
            // Defensive: writer should already guard this; keep validator self-protective.
            throw InvalidSelectOptionException::forValue(
                $internalOptionKey,
                $definition->id,
            );
        }

        if ($definition->is_multi_value) {
            throw MultiValueNotSupportedException::forDefinition($definition->id);
        }

        $allowed = $this->allowedOptionKeys($definition);

        if ($allowed === []) {
            throw InvalidSelectOptionException::optionsUndeclared($definition->id);
        }

        if (! in_array($internalOptionKey, $allowed, true)) {
            throw InvalidSelectOptionException::forValue(
                $internalOptionKey,
                $definition->id,
            );
        }
    }

    /**
     * @return list<string>
     */
    public function allowedOptionKeys(FieldDefinition $definition): array
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
