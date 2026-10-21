<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableOptionDesiredState
{
    /**
     * @param  list<AdobeConfigurableOptionValueDesiredState>  $values
     */
    public function __construct(
        public string $externalFieldKey,
        public int $attributeId,
        public string $label,
        public int $position,
        public array $values,
    ) {}
}
