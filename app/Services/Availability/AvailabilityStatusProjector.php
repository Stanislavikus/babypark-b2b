<?php

namespace App\Services\Availability;

use App\Enums\AvailabilityStatus;

final class AvailabilityStatusProjector
{
    public function fromAllocatableBalance(int $quantity): AvailabilityStatus
    {
        return $quantity > 0
            ? AvailabilityStatus::InStock
            : AvailabilityStatus::OutOfStock;
    }
}
