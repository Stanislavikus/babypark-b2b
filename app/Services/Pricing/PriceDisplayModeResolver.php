<?php

namespace App\Services\Pricing;

use App\Enums\PriceDisplayContext;
use App\Enums\PriceDisplayMode;
use App\Models\Workspace;

final class PriceDisplayModeResolver
{
    public function resolve(Workspace $workspace, PriceDisplayContext $context): PriceDisplayMode
    {
        return match ($context) {
            PriceDisplayContext::Internal,
            PriceDisplayContext::CustomerFacing => $workspace->default_price_display_mode
                ?? PriceDisplayMode::TaxInclusivePrimary,
        };
    }
}
