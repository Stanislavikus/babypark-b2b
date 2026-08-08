<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConnectorSchemaSnapshot extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_schema_source_id',
        'discovery_run_id',
        'previous_snapshot_id',
        'schema_version',
        'field_count',
        'canonical_hash',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'field_count' => 'integer',
            'captured_at' => 'datetime',
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

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(ConnectorDiscoveryRun::class, 'discovery_run_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ConnectorSchemaSnapshotField::class, 'snapshot_id');
    }

    public function diffsAsFromSnapshot(): HasMany
    {
        return $this->hasMany(ConnectorSchemaDiff::class, 'from_snapshot_id');
    }

    public function diffAsToSnapshot(): HasOne
    {
        return $this->hasOne(ConnectorSchemaDiff::class, 'to_snapshot_id');
    }
}
