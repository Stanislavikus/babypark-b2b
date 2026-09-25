<?php

namespace Tests\Support\Sync;

use App\Services\Pricing\PriceResolver;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;

final class FailingProductExecutionAggregateBuilder extends ProductExecutionAggregateBuilder
{
    public function __construct()
    {
        parent::__construct(app(PriceResolver::class));
    }

    /**
     * @param  list<string>  $productIds
     * @param  array<string, mixed>  $configurationSnapshot
     * @return list<ProductExecutionAggregate>
     */
    public function buildForProductIds(string $workspaceId, array $productIds, array $configurationSnapshot): array
    {
        throw new \RuntimeException('forced preview failure');
    }
}
