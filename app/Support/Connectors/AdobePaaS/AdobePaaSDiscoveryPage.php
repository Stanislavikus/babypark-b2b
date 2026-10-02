<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobePaaSDiscoveryPage
{
    /**
     * @param  list<mixed>  $items
     */
    public function __construct(
        #[\SensitiveParameter] public array $items,
        public int $totalCount,
    ) {}
}
