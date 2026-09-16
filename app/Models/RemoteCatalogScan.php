<?php

namespace App\Models;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RemoteCatalogScan extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'data_domain', 'target_context', 'status', 'generation', 'execution_token',
        'expected_item_count', 'received_item_count', 'failure_code', 'failure_detail',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'data_domain' => SyncDataDomain::class,
            'target_context' => 'array',
            'status' => RemoteCatalogScanStatus::class,
            'generation' => 'integer',
            'expected_item_count' => 'integer',
            'received_item_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(RemoteCatalogSnapshot::class, 'scan_id');
    }
}
