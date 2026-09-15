<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeProductRemoteStateComparator
{
    public function controlledStateMatches(
        AdobeProductDesiredState $desired,
        AdobeProductObservedState $observed,
    ): bool {
        if ($desired->sku !== $observed->sku) {
            return false;
        }

        if ($desired->name !== $observed->name) {
            return false;
        }

        if ($desired->attributeSetId !== $observed->attributeSetId) {
            return false;
        }

        if ($desired->typeId !== $observed->typeId) {
            return false;
        }

        if ($desired->status !== $observed->status) {
            return false;
        }

        if ($desired->visibility !== $observed->visibility) {
            return false;
        }

        if (! $this->pricesMatch($desired->price, $observed->price)) {
            return false;
        }

        return $this->controlledCustomAttributesMatch($desired->customAttributes, $observed->customAttributes);
    }

    public function parentControlledStateMatches(
        AdobeProductParentDesiredState $desired,
        AdobeProductParentObservedState $observed,
    ): bool {
        if ($desired->sku !== $observed->sku) {
            return false;
        }

        if ($desired->name !== $observed->name) {
            return false;
        }

        if ($desired->attributeSetId !== $observed->attributeSetId) {
            return false;
        }

        if ($desired->typeId !== $observed->typeId) {
            return false;
        }

        if ($desired->status !== $observed->status) {
            return false;
        }

        if ($desired->visibility !== $observed->visibility) {
            return false;
        }

        return $this->controlledCustomAttributesMatch($desired->customAttributes, $observed->customAttributes);
    }

    public function productStatusMatches(int $desiredStatus, AdobeProductObservedState $observed): bool
    {
        return $desiredStatus === $observed->status;
    }

    /**
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $observed
     */
    private function controlledCustomAttributesMatch(array $desired, array $observed): bool
    {
        foreach ($desired as $key => $value) {
            if (! array_key_exists($key, $observed)) {
                return false;
            }

            if (! $this->customAttributeValuesMatch($value, $observed[$key])) {
                return false;
            }
        }

        return true;
    }

    private function customAttributeValuesMatch(mixed $desired, mixed $observed): bool
    {
        if ($desired === $observed) {
            return true;
        }

        if (is_int($desired)) {
            if (is_int($observed)) {
                return $desired === $observed;
            }

            if (is_float($observed)) {
                return is_finite($observed) && floor($observed) === $observed && $desired === (int) $observed;
            }

            if (is_string($observed) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $observed) === 1) {
                return (string) ((int) $observed) === $observed && $desired === (int) $observed;
            }

            return false;
        }

        if (is_bool($desired)) {
            if (is_int($observed) && in_array($observed, [0, 1], true)) {
                return $desired === ($observed === 1);
            }

            if (is_string($observed) && in_array($observed, ['0', '1'], true)) {
                return $desired === ($observed === '1');
            }

            return false;
        }

        if (is_float($desired) && (is_int($observed) || is_float($observed) || is_string($observed))) {
            if (! is_numeric($observed)) {
                return false;
            }

            return abs($desired - (float) $observed) < 0.000000001;
        }

        if (is_string($desired) && is_int($observed)) {
            return preg_match('/^-?(?:0|[1-9][0-9]*)$/', $desired) === 1
                && (string) $observed === $desired;
        }

        return false;
    }

    private function pricesMatch(float $desired, float $observed): bool
    {
        return abs($desired - $observed) < 0.00001;
    }
}
