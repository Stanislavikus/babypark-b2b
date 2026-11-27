<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

enum AdobeSafeSyncRequiredOperation
{
    case ProductRead;
    case SimpleProductWrite;

    /** @return list<string> */
    public function requiredFamilies(): array
    {
        return match ($this) {
            self::ProductRead => [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY],
            self::SimpleProductWrite => [
                AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY,
            ],
        };
    }
}
