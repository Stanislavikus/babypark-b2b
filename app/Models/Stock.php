<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    public const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'variant_id',
        'warehouse_name',
        'quantity',
        'reserved',
        'expected_date',
        'expected_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved' => 'integer',
            'expected_date' => 'date',
            'expected_quantity' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
