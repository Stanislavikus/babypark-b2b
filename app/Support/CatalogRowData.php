<?php

namespace App\Support;

use App\Enums\CatalogPriceDisplayStatus;
use App\Enums\CatalogProductDisplayState;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Support\Pricing\CatalogRowProjection;
use App\Support\Pricing\VariantPriceDisplay;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CatalogRowData
{
    public static function forProduct(
        Product $product,
        Customer $customer,
        int $quantity = 1,
        ?PriceResolutionSnapshot $snapshot = null,
    ): CatalogRowProjection {
        $summary = app(ProductPricingSummary::class);
        $threshold = $product->category?->stock_display_threshold ?? 10;
        $activeVariants = $product->variants->where('is_active', true);

        $displaysByVariantId = $activeVariants->mapWithKeys(
            fn (ProductVariant $variant): array => [
                $variant->id => $summary->resolveVariantDisplay(
                    $variant,
                    $customer,
                    $quantity,
                    snapshot: $snapshot,
                ),
            ],
        );

        $resolvedDisplaysByVariantId = $displaysByVariantId->filter(
            static fn (VariantPriceDisplay $display): bool => $display->status === CatalogPriceDisplayStatus::Resolved,
        );

        $minQty = max(1, $product->min_order_quantity);
        $step = max(1, $product->order_step);
        $rrp = $summary->maxRrp($product);

        $inStockVariants = $activeVariants->filter(
            fn (ProductVariant $v) => self::variantAvailQty($v) > 0
        );

        $resolvedInStock = $inStockVariants->filter(
            fn (ProductVariant $v) => $resolvedDisplaysByVariantId->has($v->id)
        )->values()->all();

        usort($resolvedInStock, static function (ProductVariant $a, ProductVariant $b) use ($resolvedDisplaysByVariantId): int {
            $priceA = $resolvedDisplaysByVariantId->get($a->id)->grossPrice;
            $priceB = $resolvedDisplaysByVariantId->get($b->id)->grossPrice;

            return ($priceA <=> $priceB) ?: ($a->id <=> $b->id);
        });

        if ($resolvedInStock !== []) {
            $displayedVariant = $resolvedInStock[0];
            $priceDisplay = $resolvedDisplaysByVariantId->get($displayedVariant->id);
            $availQty = self::variantAvailQty($displayedVariant);

            return self::buildProjection(
                product: $product,
                displayState: CatalogProductDisplayState::OrderableVariantSelected,
                displayedVariant: $displayedVariant,
                priceSourceVariant: $displayedVariant,
                priceDisplay: $priceDisplay,
                maxQty: $availQty,
                minQty: $minQty,
                step: $step,
                rrp: $rrp,
                threshold: $threshold,
            );
        }

        $expectedVariants = $activeVariants->filter(
            fn (ProductVariant $v) => self::variantExpectedQty($v) > 0
                && self::variantEarliestExpectedDate($v) !== null
        );

        $resolvedExpected = $expectedVariants->filter(
            fn (ProductVariant $v) => $resolvedDisplaysByVariantId->has($v->id)
        )->values()->all();

        usort($resolvedExpected, static function (ProductVariant $a, ProductVariant $b): int {
            return (self::variantEarliestExpectedDate($a) <=> self::variantEarliestExpectedDate($b))
                ?: ($a->id <=> $b->id);
        });

        if ($resolvedExpected !== []) {
            $displayedVariant = $resolvedExpected[0];
            $priceDisplay = $resolvedDisplaysByVariantId->get($displayedVariant->id);
            $expectedQty = self::variantExpectedQty($displayedVariant);

            return self::buildProjection(
                product: $product,
                displayState: CatalogProductDisplayState::ExpectedVariantSelected,
                displayedVariant: $displayedVariant,
                priceSourceVariant: $displayedVariant,
                priceDisplay: $priceDisplay,
                maxQty: $expectedQty,
                minQty: $minQty,
                step: $step,
                rrp: $rrp,
                threshold: $threshold,
                forceExpectedBadge: true,
            );
        }

        $cheapestResolved = self::cheapestResolvedDisplay($resolvedDisplaysByVariantId);

        if ($cheapestResolved !== null) {
            $priceSourceVariant = $cheapestResolved->sourceVariant;
            $totalAvailQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantAvailQty($v));
            $totalExpQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantExpectedQty($v));
            $earliestExpDate = $activeVariants
                ->map(fn (ProductVariant $v) => self::variantEarliestExpectedDate($v))
                ->filter()
                ->sort()
                ->first();

            return new CatalogRowProjection(
                productId: $product->id,
                displayState: CatalogProductDisplayState::InformationalPriceOnly,
                displayedVariant: null,
                priceSourceVariant: $priceSourceVariant,
                price: $cheapestResolved->grossPrice,
                currency: $cheapestResolved->currency,
                priceSource: $cheapestResolved->source,
                orderable: false,
                badge: ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold),
                maxQty: 0,
                minQty: $minQty,
                step: $step,
                rrp: $rrp,
                myPriceDisplay: $cheapestResolved,
                primaryReason: null,
            );
        }

        $configurationErrors = $displaysByVariantId->filter(
            static fn (VariantPriceDisplay $display): bool => $display->status === CatalogPriceDisplayStatus::ConfigurationError,
        );

        if ($configurationErrors->isNotEmpty()) {
            $priceSourceVariant = self::minIdVariantFromDisplays($configurationErrors, $activeVariants);
            $configDisplay = $displaysByVariantId->get($priceSourceVariant->id);
            $totalAvailQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantAvailQty($v));
            $totalExpQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantExpectedQty($v));
            $earliestExpDate = $activeVariants
                ->map(fn (ProductVariant $v) => self::variantEarliestExpectedDate($v))
                ->filter()
                ->sort()
                ->first();

            return new CatalogRowProjection(
                productId: $product->id,
                displayState: CatalogProductDisplayState::ConfigurationError,
                displayedVariant: null,
                priceSourceVariant: $priceSourceVariant,
                price: null,
                currency: null,
                priceSource: null,
                orderable: false,
                badge: ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold),
                maxQty: 0,
                minQty: $minQty,
                step: $step,
                rrp: $rrp,
                myPriceDisplay: $configDisplay,
                primaryReason: $configDisplay->reason,
            );
        }

        $totalAvailQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantAvailQty($v));
        $totalExpQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantExpectedQty($v));
        $earliestExpDate = $activeVariants
            ->map(fn (ProductVariant $v) => self::variantEarliestExpectedDate($v))
            ->filter()
            ->sort()
            ->first();

        return new CatalogRowProjection(
            productId: $product->id,
            displayState: CatalogProductDisplayState::PriceUnavailable,
            displayedVariant: null,
            priceSourceVariant: null,
            price: null,
            currency: null,
            priceSource: null,
            orderable: false,
            badge: ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold),
            maxQty: 0,
            minQty: $minQty,
            step: $step,
            rrp: $rrp,
            myPriceDisplay: null,
            primaryReason: PriceResolutionReason::AllSourcesExhausted,
        );
    }

    /**
     * @param  Collection<int, VariantPriceDisplay>  $resolvedDisplaysByVariantId
     */
    private static function cheapestResolvedDisplay(Collection $resolvedDisplaysByVariantId): ?VariantPriceDisplay
    {
        if ($resolvedDisplaysByVariantId->isEmpty()) {
            return null;
        }

        $entries = $resolvedDisplaysByVariantId->values()->all();
        usort($entries, static function (VariantPriceDisplay $a, VariantPriceDisplay $b): int {
            $variantIdA = $a->sourceVariant?->id ?? PHP_INT_MAX;
            $variantIdB = $b->sourceVariant?->id ?? PHP_INT_MAX;

            return ($a->grossPrice <=> $b->grossPrice) ?: ($variantIdA <=> $variantIdB);
        });

        return $entries[0] ?? null;
    }

    /**
     * @param  Collection<int, VariantPriceDisplay>  $errorDisplays
     * @param  Collection<int, ProductVariant>  $activeVariants
     */
    private static function minIdVariantFromDisplays(Collection $errorDisplays, Collection $activeVariants): ProductVariant
    {
        $errorVariantIds = $errorDisplays->keys()->map(fn ($id) => (int) $id)->sort()->values();

        return $activeVariants->first(fn (ProductVariant $v) => $v->id === $errorVariantIds->first());
    }

    private static function buildProjection(
        Product $product,
        CatalogProductDisplayState $displayState,
        ProductVariant $displayedVariant,
        ProductVariant $priceSourceVariant,
        VariantPriceDisplay $priceDisplay,
        int $maxQty,
        int $minQty,
        int $step,
        ?float $rrp,
        int $threshold,
        bool $forceExpectedBadge = false,
    ): CatalogRowProjection {
        if ($forceExpectedBadge) {
            $badge = ProductVariant::badgeFromQty(
                0,
                self::variantExpectedQty($displayedVariant),
                self::variantEarliestExpectedDate($displayedVariant),
                $threshold,
            );
        } else {
            $availQty = self::variantAvailQty($displayedVariant);
            $badge = ProductVariant::badgeFromQty(
                $availQty,
                self::variantExpectedQty($displayedVariant),
                self::variantEarliestExpectedDate($displayedVariant),
                $threshold,
            );
        }

        return new CatalogRowProjection(
            productId: $product->id,
            displayState: $displayState,
            displayedVariant: $displayedVariant,
            priceSourceVariant: $priceSourceVariant,
            price: $priceDisplay->grossPrice,
            currency: $priceDisplay->currency,
            priceSource: $priceDisplay->source,
            orderable: $maxQty > 0,
            badge: $badge,
            maxQty: $maxQty,
            minQty: $minQty,
            step: $step,
            rrp: $rrp,
            myPriceDisplay: $priceDisplay,
            primaryReason: null,
        );
    }

    private static function variantAvailQty(ProductVariant $variant): int
    {
        return app(AvailabilityResolver::class)->netAvailable($variant);
    }

    private static function variantExpectedQty(ProductVariant $variant): int
    {
        return (int) ($variant->stocks->sum('expected_quantity') ?? 0);
    }

    private static function variantEarliestExpectedDate(ProductVariant $variant): ?Carbon
    {
        return $variant->stocks
            ->whereNotNull('expected_date')
            ->sortBy('expected_date')
            ->first()
            ?->expected_date;
    }
}
