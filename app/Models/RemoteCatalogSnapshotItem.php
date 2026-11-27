<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RemoteCatalogSnapshotItem extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'snapshot_id', 'remote_identifier', 'sku', 'name', 'remote_type',
        'remote_status', 'external_attribute_set_id', 'remote_updated_at', 'thumbnail_locator',
        'provider_brand_field_key', 'provider_brand_value', 'provider_brand_label', 'storefront_locator',
    ];

    protected function casts(): array
    {
        return [
            'external_attribute_set_id' => 'integer',
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

    public function categories(): HasMany
    {
        return $this->hasMany(RemoteCatalogSnapshotItemCategory::class, 'snapshot_item_id');
    }
}
