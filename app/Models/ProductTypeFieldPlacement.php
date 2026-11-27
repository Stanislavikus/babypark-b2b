<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTypeFieldPlacement extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = ['workspace_id', 'product_type_id', 'product_type_group_placement_id', 'field_binding_id', 'sort_order', 'required_for_completeness'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'required_for_completeness' => 'boolean'];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function groupPlacement(): BelongsTo
    {
        return $this->belongsTo(ProductTypeGroupPlacement::class, 'product_type_group_placement_id');
    }

    public function fieldBinding(): BelongsTo
    {
        return $this->belongsTo(FieldBinding::class);
    }
}
