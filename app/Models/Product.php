<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'onec_guid',
        'sku',
        'barcode_ean',
        'barcode_box',
        'name',
        'category_id',
        'brand',
        'unit',
        'min_order_quantity',
        'order_step',
        'package_quantity',
        'package_type',
        'units_per_box',
        'boxes_per_pallet',
        'lead_time_days',
        'weight_netto',
        'weight_brutto',
        'volume_m3',
        'depth_mm',
        'width_mm',
        'height_mm',
        'description',
        'images',
        'rozetka_category_id',
        'meta_title',
        'meta_description',
        'product_url',
        'is_active',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'min_order_quantity' => 'integer',
            'order_step' => 'integer',
            'weight_netto' => 'decimal:3',
            'weight_brutto' => 'decimal:3',
            'volume_m3' => 'decimal:6',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function minPriceFor(Contractor $contractor): ?float
    {
        $price = $this->variants
            ->flatMap(fn ($variant) => $variant->prices->where('contractor_id', $contractor->id))
            ->min('price_with_vat');

        return $price !== null ? (float) $price : null;
    }

    public function maxRrp(): ?float
    {
        $rrp = $this->variants
            ->flatMap(fn ($variant) => $variant->prices)
            ->max('recommended_retail_price');

        return $rrp !== null ? (float) $rrp : null;
    }
}
