<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableRemoteOptionState
{
    /**
     * @param  list<int>  $values
     */
    public function __construct(
        public int $optionId,
        public int $attributeId,
        public string $label,
        public int $position,
        public array $values,
    ) {}
}
