<?php

namespace App\Services\Pricing\Resolution;

final readonly class PriceResolutionFailure
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public PriceResolutionReason $reason,
        public string $message,
        public array $context,
    ) {}
}
