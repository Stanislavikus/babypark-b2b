<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorSchemaDiff extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_schema_source_id',
        'from_snapshot_id',
        'to_snapshot_id',
        'is_first_snapshot',
        'added_count',
        'changed_count',
        'removed_count',
        'unchanged_count',
    ];

    protected function casts(): array
    {
        return [
            'is_first_snapshot' => 'boolean',
            'added_count' => 'integer',
            'changed_count' => 'integer',
            'removed_count' => 'integer',
            'unchanged_count' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function schemaSource(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSource::class, 'connector_schema_source_id');
    }

    public function fromSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshot::class, 'from_snapshot_id');
    }

    public function toSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshot::class, 'to_snapshot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConnectorSchemaDiffItem::class);
    }
}
