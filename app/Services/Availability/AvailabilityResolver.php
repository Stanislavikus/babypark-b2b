<?php

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class AvailabilityResolver
{
    /**
     * Net sellable quantity for a variant (read-only, no locking).
     */
    public function netAvailable(ProductVariant $variant): int
    {
        $pending = $this->activePendingReservationsSum($variant);

        return (int) $variant->available_quantity_cache - $pending;
    }

    public function activePendingReservationsSum(ProductVariant $variant): int
    {
        return (int) Reservation::query()
            ->where('variant_id', $variant->id)
            ->where('status', ReservationStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');
    }

    /**
     * SQL expression: pending unexpired reservation quantity for a single variant row.
     *
     * @param  string  $variantIdColumn  e.g. "pv.id" or "product_variants.id"
     */
    public static function pendingReservationsSql(string $variantIdColumn): string
    {
        $now = DB::connection()->getPdo()->quote(now()->toDateTimeString());

        return "(SELECT COALESCE(SUM(r.quantity), 0)
                 FROM reservations r
                 WHERE r.variant_id = {$variantIdColumn}
                 AND r.status = 'pending'
                 AND (r.expires_at IS NULL OR r.expires_at > {$now}))";
    }

    /**
     * SQL expression: net available quantity for one variant (cache minus pending reservations).
     */
    public static function netAvailableForVariantSql(string $variantIdColumn, string $cacheColumn = 'product_variants.available_quantity_cache'): string
    {
        $pending = self::pendingReservationsSql($variantIdColumn);

        return "({$cacheColumn} - {$pending})";
    }

    /**
     * SQL subquery: sum of net available quantity across all variants of a product.
     * Preserves product-level aggregation used by admin table filters/sorts.
     */
    public static function netQtySqlForProduct(string $productIdColumn = 'products.id'): string
    {
        $pending = self::pendingReservationsSql('pv.id');

        return "(SELECT COALESCE(SUM(
                    CASE WHEN (pv.available_quantity_cache - {$pending}) > 0
                         THEN (pv.available_quantity_cache - {$pending})
                         ELSE 0 END
                ), 0)
                 FROM product_variants pv
                 WHERE pv.product_id = {$productIdColumn})";
    }
}
