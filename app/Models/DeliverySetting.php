<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySetting extends Model
{
    protected $fillable = [
        'city',
        'free_from',
        'delivery_price',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'free_from' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
