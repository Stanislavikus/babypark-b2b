<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportExecutionMetadata
{
    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    public function __construct(
        public int $selectedAttributeSetId,
        public array $attributeSets,
    ) {}
}
