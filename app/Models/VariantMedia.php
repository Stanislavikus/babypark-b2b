<?php

namespace App\Models;

use App\Enums\MediaRole;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VariantMedia extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'variant_media';

    protected $fillable = [
        'workspace_id',
        'variant_id',
        'media_asset_id',
        'role',
        'sort_order',
        'locale',
    ];

    protected $hidden = ['primary_marker'];

    protected static function booted(): void
    {
        static::saving(function (VariantMedia $media): void {
            $asset = MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $media->workspace_id)
                ->whereKey($media->media_asset_id)
                ->first();

            if (! $asset instanceof MediaAsset || ! $asset->isOriginal()) {
                throw new LogicException('Variant gallery media must reference an Original MediaAsset in the same workspace.');
            }

            if ($media->role === MediaRole::Primary && (int) $media->sort_order !== 0) {
                throw new LogicException('Primary VariantMedia must have sort_order 0.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => MediaRole::class,
            'sort_order' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
