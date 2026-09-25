<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class AdobeSafeSyncSimpleProductWriteRequest
{
    /**
     * @param  list<AdobeSafeSyncSimpleProductWriteCustomAttribute>  $mappedAttributes
     */
    public function __construct(
        public string $expectedSku,
        public ?string $name = null,
        public ?int $status = null,
        public ?int $visibility = null,
        public ?float $price = null,
        public array $mappedAttributes = [],
    ) {}
}
