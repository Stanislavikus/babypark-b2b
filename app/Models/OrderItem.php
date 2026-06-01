<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'variant_id',
        'sku',
        'name',
        'attributes',
        'quantity',
        'price',
        'price_with_vat',
        'total',
        'manager_price',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'price_with_vat' => 'decimal:2',
            'total' => 'decimal:2',
            'manager_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
