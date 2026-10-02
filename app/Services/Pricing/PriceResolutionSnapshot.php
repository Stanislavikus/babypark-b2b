<?php

namespace App\Services\Pricing;

use App\Services\Pricing\Resolution\PriceResolutionResult;
use Carbon\CarbonImmutable;

final class PriceResolutionSnapshot
{
    /** @var array<string, PriceResolutionResult> */
    private array $cache = [];

    public function __construct(
        public readonly CarbonImmutable $effectiveAt,
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    public function get(string $key): PriceResolutionResult
    {
        return $this->cache[$key];
    }

    public function put(string $key, PriceResolutionResult $result): PriceResolutionResult
    {
        return $this->cache[$key] = $result;
    }
}
