<?php

namespace App\Support\Connectors\AdobePaaS;

use Carbon\CarbonImmutable;

final readonly class AdobeProductAttributeStructureSnapshot
{
    /**
     * @param  list<array{provider_attribute_id:int, external_field_key:string, frontend_input:?string}>  $attributes
     * @param  list<array{provider_attribute_set_id:int, name:string}>  $attributeSets
     * @param  list<array{provider_attribute_group_id:int, provider_attribute_set_id:int, name:string}>  $attributeGroups
     * @param  list<array{provider_attribute_id:int, provider_attribute_set_id:int}>  $setMemberships
     * @param  list<array{provider_attribute_id:int, provider_option_id:string, label:string}>  $options
     */
    public function __construct(
        public string $storeCode,
        public CarbonImmutable $capturedAt,
        public array $attributes,
        public array $attributeSets,
        public array $attributeGroups,
        public array $setMemberships,
        public array $options,
    ) {}
}
