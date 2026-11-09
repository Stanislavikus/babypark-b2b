<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdobeProductAttributeMaterialization extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'connector_schema_source_id',
        'adobe_product_attribute_lineage_id', 'field_definition_id', 'materialized_at',
    ];

    protected function casts(): array
    {
        return ['materialized_at' => 'datetime'];
    }

    public function lineage(): BelongsTo
    {
        return $this->belongsTo(AdobeProductAttributeLineage::class, 'adobe_product_attribute_lineage_id');
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class);
    }
}
