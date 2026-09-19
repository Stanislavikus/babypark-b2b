<?php

namespace App\Services\Pricing\Resolution;

final readonly class PriceResolutionTrace
{
    /**
     * @param  list<PriceResolutionStep>  $steps
     */
    public function __construct(public array $steps) {}
}
