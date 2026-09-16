<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RemoteCatalogSnapshotItem extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'snapshot_id', 'remote_identifier', 'sku', 'name', 'remote_type',
        'remote_status', 'remote_updated_at', 'thumbnail_locator', 'storefront_locator',
    ];

    protected function casts(): array
    {
        return [
            'remote_updated_at' => 'datetime',
            'storefront_locator' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $assertMutable = static function (self $item): void {
            $publishedAt = RemoteCatalogSnapshot::withoutWorkspaceScope()
                ->whereKey($item->snapshot_id)
                ->value('published_at');

            if ($publishedAt !== null) {
                throw new LogicException('Items in a published remote catalogue snapshot are immutable.');
            }
        };

        static::updating($assertMutable);
        static::deleting($assertMutable);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RemoteCatalogSnapshot::class, 'snapshot_id');
    }
}
