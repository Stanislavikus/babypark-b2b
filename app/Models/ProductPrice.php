<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    protected $fillable = [
        'variant_id',
        'contractor_id',
        'price_type_id',
        'value',
        'currency',
        'source',
    ];

    // NOTE: `value` is intentionally NOT cast to `decimal`. The Eloquent decimal cast
    // routes through brick/math, which on SQLite receives the driver's native float and
    // emits a deprecation. PriceResolver reads the raw value and normalizes it with bcmath
    // (bcadd(..., 2)) instead, keeping money strictly string-based on every driver.

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }
}
