<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

class PriceNotAvailableException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
