<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductAttributeMaterializationResult
{
    public function __construct(
        public int $eligibleFields,
        public int $definitions,
        public int $bindings,
        public int $fieldMappings,
        public int $optionMappings,
        public int $deferredFields,
    ) {}
}
