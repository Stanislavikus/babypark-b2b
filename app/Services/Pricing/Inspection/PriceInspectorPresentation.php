<?php

namespace App\Services\Pricing\Inspection;

final readonly class PriceInspectorPresentation
{
    /**
     * @param  list<PriceInspectorSourceStep>  $sourceSteps
     * @param  list<PriceInspectorAction>  $recommendedActions
     * @param  array<string, mixed>  $technicalDetails
     */
    public function __construct(
        public string $headline,
        public PriceInspectorTone $tone,
        public ?string $priceSummary,
        public string $summary,
        public array $sourceSteps,
        public array $recommendedActions,
        public array $technicalDetails,
    ) {}
}
