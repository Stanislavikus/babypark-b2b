<?php

namespace App\Services\Pricing\Inspection;

use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class PriceInspectorContext
{
    public function __construct(
        public Customer $customer,
        public ProductVariant $variant,
        public int $quantity,
        public CarbonImmutable $effectiveAt,
        public ?User $user,
    ) {}
}
