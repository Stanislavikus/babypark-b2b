<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableChildLinkDesiredState
{
    public function __construct(
        public string $variantId,
        public string $childSku,
    ) {}
}
