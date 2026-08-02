<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorSchemaSnapshotField extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'snapshot_id',
        'external_field_key',
        'external_label',
        'normalized_data_type',
        'is_required',
        'is_multi_value',
        'is_localizable',
        'external_scope',
        'normalized_payload',
        'canonical_hash',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_multi_value' => 'boolean',
            'is_localizable' => 'boolean',
            'normalized_payload' => 'object',
            'sort_order' => 'integer',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshot::class, 'snapshot_id');
    }

    public function beforeDiffItems(): HasMany
    {
        return $this->hasMany(ConnectorSchemaDiffItem::class, 'before_snapshot_field_id');
    }

    public function afterDiffItems(): HasMany
    {
        return $this->hasMany(ConnectorSchemaDiffItem::class, 'after_snapshot_field_id');
    }
}
