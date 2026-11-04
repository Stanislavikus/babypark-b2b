<?php

namespace App\Support\Connectors\AdobePaaS;

use Carbon\CarbonImmutable;

final readonly class AdobeProductAttributeStructureReconcileResult
{
    public function __construct(
        public CarbonImmutable $capturedAt,
        public int $attributes,
        public int $attributeSets,
        public int $attributeGroups,
        public int $setMemberships,
        public int $options,
    ) {}
}
