<?php

namespace App\Models;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorSchemaFieldClassification extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'connector_schema_source_id', 'external_field_key',
        'latest_snapshot_field_id', 'disposition',
        'behavior_class', 'behavior_signature', 'runtime_owner_hint', 'canonical_code',
        'mapping_strategy', 'classifier_version', 'reason_code', 'classified_canonical_hash', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'disposition' => ConnectorSchemaFieldDisposition::class,
            'behavior_signature' => 'array',
            'computed_at' => 'datetime',
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

    public function latestSnapshotField(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshotField::class, 'latest_snapshot_field_id');
    }
}
