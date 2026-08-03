<?php

namespace App\Models;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorDiscoveryRun extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_schema_source_id',
        'trigger',
        'initiated_by_user_id',
        'status',
        'execution_attempts',
        'retry_until_at',
        'next_attempt_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'fields_received',
        'fields_normalized',
        'added_count',
        'changed_count',
        'removed_count',
        'unchanged_count',
        'cause_category',
        'actionability',
        'error_code',
        'http_status',
        'user_message_key',
        'technical_summary',
        'vendor_request_id',
        'snapshot_id',
        'previous_snapshot_id',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => ConnectorDiscoveryRunTrigger::class,
            'status' => ConnectorDiscoveryRunStatus::class,
            'cause_category' => ConnectorErrorCause::class,
            'actionability' => ConnectorErrorActionability::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'fields_received' => 'integer',
            'fields_normalized' => 'integer',
            'added_count' => 'integer',
            'changed_count' => 'integer',
            'removed_count' => 'integer',
            'unchanged_count' => 'integer',
            'execution_attempts' => 'integer',
            'retry_until_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshot::class, 'snapshot_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSnapshot::class, 'previous_snapshot_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ConnectorDiscoveryRunStatus::Succeeded,
            ConnectorDiscoveryRunStatus::Failed,
            ConnectorDiscoveryRunStatus::Cancelled,
        ], true);
    }

    public function hasVendorClassification(): bool
    {
        if ($this->error_code === null) {
            return false;
        }

        return ConnectorDiscoveryRunErrorCode::tryFrom($this->error_code) !== null;
    }
}
