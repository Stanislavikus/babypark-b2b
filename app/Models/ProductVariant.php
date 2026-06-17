<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'onec_guid',
        'sku',
        'barcode_ean',
        'attributes',
        'is_active',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'variant_id');
    }

    /**
     * Shared badge computation from raw availability data.
     *
     * Returns an array with:
     *   label, color (success/warning/info/danger),
     *   and optionally available_quantity, expected_quantity, expected_date.
     *
     * The threshold only affects whether we show exact count or "В наявності";
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
                'label'              => 'В наявності',
                'color'              => 'success',
                'available_quantity' => $availQty,
            ];
        }

        if ($availQty > 0) {
            return [
                'label'              => "Залишилось {$availQty} шт",
                'color'              => 'warning',
                'available_quantity' => $availQty,
            ];
        }

        if ($expectedDate) {
            return [
                'label'             => 'Очікується ' . $expectedDate->format('d.m'),
                'color'             => 'info',
                'expected_quantity' => $expectedQty,
                'expected_date'     => $expectedDate,
            ];
        }

        return [
            'label' => 'Немає в наявності',
            'color' => 'danger',
        ];
    }

    /**
     * Compute availability badge for this variant (aggregate across all its warehouses).
     *
     * Requires $this->stocks to be loaded.
     *
     * @return array{label: string, color: string, available_quantity?: int, expected_quantity?: int, expected_date?: Carbon|null}
     */
    public function availabilityBadge(int $threshold): array
    {
        $availQty    = $this->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0));
        $expectedQty = $this->stocks->sum('expected_quantity') ?? 0;
        $expectedDate = $this->stocks
            ->whereNotNull('expected_date')
            ->sortBy('expected_date')
            ->first()
            ?->expected_date;

        return static::badgeFromQty($availQty, $expectedQty, $expectedDate, $threshold);
    }
}
