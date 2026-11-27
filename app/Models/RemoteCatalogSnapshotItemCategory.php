<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RemoteCatalogSnapshotItemCategory extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'snapshot_item_id',
        'external_category_id',
        'category_path',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $assertMutable = static function (self $category): void {
            $publishedAt = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
                ->join('remote_catalog_snapshots', 'remote_catalog_snapshots.id', '=', 'remote_catalog_snapshot_items.snapshot_id')
                ->where('remote_catalog_snapshot_items.id', $category->snapshot_item_id)
                ->value('remote_catalog_snapshots.published_at');

            if ($publishedAt !== null) {
                throw new LogicException('Categories in a published remote catalogue snapshot are immutable.');
            }
        };

        static::updating($assertMutable);
        static::deleting($assertMutable);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RemoteCatalogSnapshotItem::class, 'snapshot_item_id');
    }
}
