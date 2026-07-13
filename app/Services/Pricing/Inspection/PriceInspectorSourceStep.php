<?php

namespace App\Services\Pricing\Inspection;

final readonly class PriceInspectorSourceStep
{
    public function __construct(
        public string $sourceLabel,
        public ?string $sourceName,
        public string $outcomeLabel,
        public string $explanation,
        public ?PriceInspectorAction $action,
    ) {}
}
