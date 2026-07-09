<?php

namespace App\Models;

use App\Enums\InventoryRecordSourceType;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRecord extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'product_variant_id',
        'inventory_location_id',
        'location_name_snapshot',
        'source_type',
        'source_reference_id',
        'quantity_change',
        'resulting_quantity',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => InventoryRecordSourceType::class,
            'quantity_change' => 'integer',
            'resulting_quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
