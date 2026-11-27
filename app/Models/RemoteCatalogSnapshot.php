<?php

namespace App\Models;

use App\Enums\SyncDataDomain;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RemoteCatalogSnapshot extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'data_domain', 'scan_id', 'previous_snapshot_id',
        'target_context', 'item_count', 'captured_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'data_domain' => SyncDataDomain::class,
            'target_context' => 'array',
            'item_count' => 'integer',
            'captured_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            if ($snapshot->getOriginal('published_at') !== null) {
                throw new LogicException('Published remote catalogue snapshots are immutable.');
            }
        });

        static::deleting(function (self $snapshot): void {
            if ($snapshot->published_at !== null) {
                throw new LogicException('Published remote catalogue snapshots are immutable.');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(RemoteCatalogScan::class, 'scan_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemoteCatalogSnapshotItem::class, 'snapshot_id');
    }
}
