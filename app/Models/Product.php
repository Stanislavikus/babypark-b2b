<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'onec_guid',
        'sku',
        'barcode_ean',
        'barcode_box',
        'name',
        'category_id',
        'brand',
        'merchant_type',
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->using(ProductTag::class)
            ->withPivot('workspace_id')
            ->withPivotValue('workspace_id', $this->relationWorkspaceId());
    }

    private function relationWorkspaceId(): string
    {
        return (string) ($this->getAttribute('workspace_id') ?? app(WorkspaceContext::class)->id());
    }
}
