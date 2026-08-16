<?php

namespace App\Models;

use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'sync_configuration_id',
        'configuration_revision',
        'mode',
        'semantic_operation',
        'status',
        'initiated_by_user_id',
        'configuration_snapshot',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SyncRunMode::class,
            'semantic_operation' => SyncSemanticOperation::class,
            'status' => SyncRunStatus::class,
            'configuration_snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function syncConfiguration(): BelongsTo
    {
        return $this->belongsTo(SyncConfiguration::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class);
    }
}
