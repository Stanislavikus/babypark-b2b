<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeGroup extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = ['workspace_id', 'code', 'localized_labels', 'description', 'status'];

    protected function casts(): array
    {
        return ['localized_labels' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function productTypePlacements(): HasMany
    {
        return $this->hasMany(ProductTypeGroupPlacement::class);
    }
}
