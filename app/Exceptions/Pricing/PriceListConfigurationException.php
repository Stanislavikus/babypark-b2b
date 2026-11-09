<?php

namespace App\Exceptions\Pricing;

use App\Services\Pricing\Resolution\PriceResolutionReason;
use RuntimeException;

class PriceListConfigurationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly PriceResolutionReason $reason = PriceResolutionReason::DefaultPriceListMisconfigured,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
