<?php

namespace App\Services\Pricing\Inspection;

use App\Services\Pricing\Resolution\PriceResolutionStepStatus;

final readonly class PriceInspectorSourceStep
{
    public function __construct(
        public string $sourceLabel,
        public ?string $sourceName,
        public string $outcomeLabel,
        public string $explanation,
        public ?PriceInspectorAction $action,
        public PriceResolutionStepStatus $stepStatus,
    ) {}
}
