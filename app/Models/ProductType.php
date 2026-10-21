<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductType extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'code', 'localized_labels', 'description', 'status', 'is_default', 'structure_revision',
    ];

    protected function casts(): array
    {
        return ['localized_labels' => 'array', 'is_default' => 'boolean', 'structure_revision' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function groupPlacements(): HasMany
    {
        return $this->hasMany(ProductTypeGroupPlacement::class);
    }

    public function fieldPlacements(): HasMany
    {
        return $this->hasMany(ProductTypeFieldPlacement::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
