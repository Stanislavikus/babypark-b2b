<?php

namespace App\Models;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorAccount extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $hidden = [
        'credentials',
    ];

    protected $fillable = [
        'workspace_id',
        'connector_definition_id',
        'name',
        'auth_profile',
        'base_url',
        'store_code',
        'tenant_context',
        'is_enabled',
        'settings',
        'credentials',
        'connection_status',
        'last_checked_at',
        'last_successful_check_at',
        'last_discovery_at',
        'last_successful_discovery_at',
        'last_error_cause',
        'last_error_actionability',
        'last_error_message_key',
        'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'connection_status' => ConnectorAccountConnectionStatus::class,
            'last_checked_at' => 'datetime',
            'last_successful_check_at' => 'datetime',
            'last_discovery_at' => 'datetime',
            'last_successful_discovery_at' => 'datetime',
            'last_error_cause' => ConnectorErrorCause::class,
            'last_error_actionability' => ConnectorErrorActionability::class,
            'last_error_at' => 'datetime',
        ];
    }

    public function connectorDefinition(): BelongsTo
    {
        return $this->belongsTo(ConnectorDefinition::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connectionChecks(): HasMany
    {
        return $this->hasMany(ConnectorConnectionCheck::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(ConnectorDiscoveryRun::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ConnectorSchemaSnapshot::class);
    }

    public function diffs(): HasMany
    {
        return $this->hasMany(ConnectorSchemaDiff::class);
    }
}
