<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    public const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'contractor_id',
        'variant_id',
        'price',
        'price_with_vat',
        'vat_rate',
        'recommended_retail_price',
        'min_quantity',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_with_vat' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'recommended_retail_price' => 'decimal:2',
            'min_quantity' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
