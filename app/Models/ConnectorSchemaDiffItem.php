<?php

namespace App\Models;

use App\Enums\ConnectorSchemaDiffItemChangeType;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorSchemaDiffItem extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'connector_schema_diff_id',
        'change_type',
        'external_field_key',
        'before_snapshot_field_id',
        'after_snapshot_field_id',
        'changed_paths',
    ];

    protected function casts(): array
    {
        return [
            'change_type' => ConnectorSchemaDiffItemChangeType::class,
            'changed_paths' => 'array',
        ];
    }

    public function diff(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaDiff::class, 'connector_schema_diff_id');
    }

    public function beforeField(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshotField::class, 'before_snapshot_field_id');
    }

    public function afterField(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshotField::class, 'after_snapshot_field_id');
    }
}
