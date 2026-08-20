<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableOptionValueDesiredState
{
    public function __construct(
        public int $valueIndex,
        public string $label,
    ) {}
}
