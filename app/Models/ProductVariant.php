<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use App\Services\Availability\AvailabilityResolver;
use App\Support\Workspace\BelongsToWorkspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'product_id',
        'onec_guid',
        'sku',
        'barcode_ean',
        'attributes',
        'is_active',
        'available_quantity_cache',
        'availability_status',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_active' => 'boolean',
            'available_quantity_cache' => 'integer',
            'availability_status' => AvailabilityStatus::class,
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'variant_id');
    }

    public function priceFor(Contractor $contractor): ?float
    {
        $price = $this->prices->firstWhere('contractor_id', $contractor->id);

        return $price?->price_with_vat !== null ? (float) $price->price_with_vat : null;
    }

    /**
     * Shared badge computation from raw availability data.
     *
     * Returns an array with:
     *   label, color (success/warning/info/danger),
     *   and optionally available_quantity, expected_quantity, expected_date.
     *
     * The threshold controls the ">N / =N / bare N" suffix after "У наявності:";
     * it does NOT limit the quantity counter max.
     *
     * @return array{label: string, color: string, available_quantity?: int, expected_quantity?: int, expected_date?: Carbon|null}
     */
    public static function badgeFromQty(
        int $availQty,
        int $expectedQty,
        ?Carbon $expectedDate,
        int $threshold
    ): array {
        if ($availQty > $threshold) {
            return [
                'label' => "У наявності: >{$threshold} шт.",
                'color' => 'success',
                'available_quantity' => $availQty,
            ];
        }

        if ($availQty === $threshold) {
            return [
                'label' => "У наявності: ={$threshold} шт.",
                'color' => 'warning',
                'available_quantity' => $availQty,
            ];
        }

        if ($availQty > 0) {
            return [
                'label' => "У наявності: {$availQty} шт.",
                'color' => 'warning',
                'available_quantity' => $availQty,
            ];
        }

        if ($expectedDate) {
            return [
                'label' => 'Очікується '.$expectedDate->format('d.m'),
                'color' => 'info',
                'expected_quantity' => $expectedQty,
                'expected_date' => $expectedDate,
            ];
        }

        return [
            'label' => 'Немає в наявності',
            'color' => 'danger',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'variant_id');
    }

    /**
     * Compute availability badge for this variant (aggregate across all locations).
     *
     * Requires $this->stocks to be loaded for expected-date display.
     *
     * @return array{label: string, color: string, available_quantity?: int, expected_quantity?: int, expected_date?: Carbon|null}
     */
    public function availabilityBadge(int $threshold): array
    {
        $availQty = app(AvailabilityResolver::class)->netAvailable($this);
        $expectedQty = $this->stocks->sum('expected_quantity') ?? 0;
        $expectedDate = $this->stocks
            ->whereNotNull('expected_date')
            ->sortBy('expected_date')
            ->first()
            ?->expected_date;

        return static::badgeFromQty($availQty, $expectedQty, $expectedDate, $threshold);
    }
}
