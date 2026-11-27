<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class AdobeSafeSyncSimpleProductWriteCustomAttribute
{
    public function __construct(
        public string $attributeCode,
        public string $value,
    ) {}
}
