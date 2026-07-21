<?php

namespace App\Models;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorConnectionCheck extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'trigger',
        'initiated_by_user_id',
        'status',
        'cause_category',
        'actionability',
        'error_code',
        'http_status',
        'user_message_key',
        'safe_message_parameters',
        'technical_summary',
        'vendor_request_id',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => ConnectorConnectionCheckTrigger::class,
            'status' => ConnectorConnectionCheckStatus::class,
            'cause_category' => ConnectorErrorCause::class,
            'actionability' => ConnectorErrorActionability::class,
            'safe_message_parameters' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
