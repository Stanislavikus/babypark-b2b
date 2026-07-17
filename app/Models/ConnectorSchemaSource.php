<?php

namespace App\Models;

use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorSchemaSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'connector_definition_id',
        'code',
        'label',
        'source_kind',
        'acquisition_mode',
        'schema_scope',
        'reference_url',
        'endpoint_path',
        'schema_version',
        'is_primary',
        'verification_status',
        'last_verified_at',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'source_kind' => ConnectorSchemaSourceKind::class,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::class,
            'schema_scope' => ConnectorSchemaScope::class,
            'verification_status' => ConnectorSchemaVerificationStatus::class,
            'is_primary' => 'boolean',
            'last_verified_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function connectorDefinition(): BelongsTo
    {
        return $this->belongsTo(ConnectorDefinition::class);
    }
}
