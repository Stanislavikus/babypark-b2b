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

        return $desired->customAttributes === $observed->customAttributes;
    }

    private function pricesMatch(float $desired, float $observed): bool
    {
        return abs($desired - $observed) < 0.00001;
    }
}
