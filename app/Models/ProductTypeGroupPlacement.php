<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductTypeGroupPlacement extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = ['workspace_id', 'product_type_id', 'attribute_group_id', 'sort_order', 'is_optional', 'default_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_optional' => 'boolean', 'default_active' => 'boolean'];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function attributeGroup(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class);
    }

    public function fieldPlacements(): HasMany
    {
        return $this->hasMany(ProductTypeFieldPlacement::class);
    }
}
