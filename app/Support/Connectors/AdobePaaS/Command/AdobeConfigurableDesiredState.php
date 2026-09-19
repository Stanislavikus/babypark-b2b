<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableDesiredState
{
    /**
     * @param  list<AdobeConfigurableOptionDesiredState>  $options
     * @param  list<string>  $activeChildVariantIds
     * @param  list<AdobeConfigurableChildLinkDesiredState>  $childLinks
     */
    public function __construct(
        public int $productId,
        public string $parentSku,
        public AdobeProductParentDesiredState $parent,
        public array $options,
        public array $activeChildVariantIds,
        public array $childLinks,
    ) {}
}
