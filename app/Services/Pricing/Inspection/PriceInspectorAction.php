<?php

namespace App\Services\Pricing\Inspection;

final readonly class PriceInspectorAction
{
    public function __construct(
        public string $label,
        public string $url,
        public string $deduplicationKey,
    ) {}
}
