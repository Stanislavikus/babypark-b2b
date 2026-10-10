<?php

namespace App\Support\Sync\EntityTrust;

final readonly class EntityTrustSubjectReview
{
    /**
     * @param  list<EntityTrustControlledFieldComparison>  $fieldComparisons
     */
    public function __construct(
        public string $subjectKey,
        public string $expectedSku,
        public string $expectedMagentoType,
        public ?string $platformName,
        public array $fieldComparisons,
        public ?EntityTrustMediaSummary $mediaSummary = null,
    ) {}
}
