<?php

namespace App\Support\Pricing;

use App\Enums\CatalogProductDisplayState;
use App\Models\ProductVariant;
use App\Services\Pricing\Resolution\PriceResolutionReason;

final readonly class CatalogRowProjection
{
    /**
     * @param  array{label: string, color: string}  $badge
     */
    public function __construct(
        public int $productId,
        public CatalogProductDisplayState $displayState,
        public ?ProductVariant $displayedVariant,
        public ?ProductVariant $priceSourceVariant,
        public ?float $price,
        public ?string $currency,
        public ?string $priceSource,
        public bool $orderable,
        public array $badge,
        public int $maxQty,
        public int $minQty,
        public int $step,
        public ?float $rrp,
        public ?VariantPriceDisplay $myPriceDisplay,
        public ?PriceResolutionReason $primaryReason,
    ) {}

    /** @deprecated Use displayedVariant */
    public function firstVariant(): ?ProductVariant
    {
        return $this->displayedVariant;
    }

    /** @deprecated Use price */
    public function myPrice(): ?float
    {
        return $this->price;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'badge' => $this->badge,
            'firstVariant' => $this->displayedVariant,
            'maxQty' => $this->maxQty,
            'minQty' => $this->minQty,
            'step' => $this->step,
            'myPrice' => $this->price,
            'myPriceDisplay' => $this->myPriceDisplay,
            'rrp' => $this->rrp,
            'displayState' => $this->displayState,
            'priceSourceVariant' => $this->priceSourceVariant,
            'currency' => $this->currency,
            'priceSource' => $this->priceSource,
            'orderable' => $this->orderable,
            'primaryReason' => $this->primaryReason,
        ];
    }
}
