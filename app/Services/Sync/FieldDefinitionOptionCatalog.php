<?php

namespace App\Services\Sync;

use App\Models\FieldDefinition;

final class FieldDefinitionOptionCatalog
{
    /**
     * @return list<string>
     */
    public function currentOptionCodes(FieldDefinition $definition): array
    {
        $validationRules = $definition->validation_rules;

        if (! is_array($validationRules)) {
            return [];
        }

        $options = $validationRules['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $codes = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $code = $option['code'] ?? null;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function localizedOptionLabel(FieldDefinition $definition, string $code, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $validationRules = $definition->validation_rules;

        if (! is_array($validationRules)) {
            return $code;
        }

        $options = $validationRules['options'] ?? [];

        if (! is_array($options)) {
            return $code;
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (($option['code'] ?? null) !== $code) {
                continue;
            }

            $labels = $option['labels'] ?? [];

            if (! is_array($labels)) {
                return $code;
            }

            if (isset($labels[$locale]) && is_string($labels[$locale]) && $labels[$locale] !== '') {
                return $labels[$locale];
            }

            if (isset($labels['uk']) && is_string($labels['uk']) && $labels['uk'] !== '') {
                return $labels['uk'];
            }

            return $code;
        }

        return $code;
    }
}
