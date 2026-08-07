<?php

namespace App\Support\Connectors\AdobePaaS;

final class AdobePaaSServiceOnlyAttributeEligibility
{
    /**
     * Whether a raw Magento attribute should be counted as received but excluded
     * from normalization because it is a Magento internal/service-only field.
     */
    public function shouldSkip(#[\SensitiveParameter] mixed $rawItem): bool
    {
        if (! $rawItem instanceof \stdClass) {
            return false;
        }

        if (! $this->hasValidAttributeCode($rawItem)) {
            return false;
        }

        if (! property_exists($rawItem, 'frontend_input')) {
            return false;
        }

        if (! is_null($rawItem->frontend_input)) {
            return false;
        }

        if (! property_exists($rawItem, 'is_user_defined')) {
            return false;
        }

        if (! is_bool($rawItem->is_user_defined) || $rawItem->is_user_defined !== false) {
            return false;
        }

        if (! property_exists($rawItem, 'is_visible')) {
            return false;
        }

        if (! is_bool($rawItem->is_visible) || $rawItem->is_visible !== false) {
            return false;
        }

        return true;
    }

    /**
     * Structural identifier validation shared with the normalizer contract.
     */
    public function hasValidAttributeCode(#[\SensitiveParameter] \stdClass $raw): bool
    {
        if (! property_exists($raw, 'attribute_code')) {
            return false;
        }

        $value = $raw->attribute_code;

        if (! is_string($value) || $value === '') {
            return false;
        }

        return mb_check_encoding($value, 'UTF-8');
    }
}
