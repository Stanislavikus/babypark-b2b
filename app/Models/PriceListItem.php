<?php

namespace App\Models;

use App\Enums\PriceListItemStatus;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'price_list_id',
        'product_variant_id',
        'quantity_min',
        'price',
        'sale_price',
        'vat_rate',
        'valid_from',
        'valid_until',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity_min' => 'integer',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'status' => PriceListItemStatus::class,
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
