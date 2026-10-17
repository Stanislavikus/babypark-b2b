<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductActiveOptionalGroup extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = ['workspace_id', 'product_id', 'product_type_group_placement_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function groupPlacement(): BelongsTo
    {
        return $this->belongsTo(ProductTypeGroupPlacement::class, 'product_type_group_placement_id');
    }
}
