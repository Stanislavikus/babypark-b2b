<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

final readonly class AdobeProductExtraChildrenDiscoveryResult
{
    /**
     * @param  list<string>  $extraChildSkus
     */
    public function __construct(
        public bool $isAvailable,
        public array $extraChildSkus = [],
    ) {}
}
