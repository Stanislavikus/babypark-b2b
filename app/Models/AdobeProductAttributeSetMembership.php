<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdobeProductAttributeSetMembership extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'connector_schema_source_id',
        'adobe_product_attribute_lineage_id', 'adobe_product_attribute_set_id',
        'first_seen_at', 'last_seen_at', 'missing_since',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }

    public function lineage(): BelongsTo
    {
        return $this->belongsTo(AdobeProductAttributeLineage::class, 'adobe_product_attribute_lineage_id');
    }

    public function attributeSet(): BelongsTo
    {
        return $this->belongsTo(AdobeProductAttributeSet::class, 'adobe_product_attribute_set_id');
    }
}
