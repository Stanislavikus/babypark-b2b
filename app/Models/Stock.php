<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'workspace_id',
        'variant_id',
        'inventory_location_id',
        'quantity',
        'expected_date',
        'expected_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expected_date' => 'date',
            'expected_quantity' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
