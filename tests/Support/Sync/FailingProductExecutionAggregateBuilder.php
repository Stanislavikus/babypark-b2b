<?php

namespace Tests\Support\Sync;

use App\Services\Pricing\PriceResolver;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use Illuminate\Support\Collection;

final class FailingProductExecutionAggregateBuilder extends ProductExecutionAggregateBuilder
{
    public function __construct()
    {
        parent::__construct(app(PriceResolver::class));
    }

    public function buildForProducts(Collection $products): array
    {
        throw new \RuntimeException('forced preview failure');
    }
}
