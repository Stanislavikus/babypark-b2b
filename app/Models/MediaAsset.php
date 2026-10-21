<?php

namespace App\Models;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'parent_media_asset_id',
        'asset_type',
        'storage_disk',
        'storage_path',
        'source_url',
        'original_filename',
        'mime_type',
        'byte_size',
        'content_sha256',
        'width_px',
        'height_px',
        'diagnosis_status',
        'diagnosis_json',
        'provenance_json',
        'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'asset_type' => MediaAssetType::class,
            'diagnosis_status' => MediaDiagnosisStatus::class,
            'byte_size' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'diagnosis_json' => 'array',
            'provenance_json' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_media_asset_id');
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(self::class, 'parent_media_asset_id');
    }

    public function productMedia(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'media_asset_id');
    }

    public function variantMedia(): HasMany
    {
        return $this->hasMany(VariantMedia::class, 'media_asset_id');
    }

    public function brandsAsLogo(): HasMany
    {
        return $this->hasMany(Brand::class, 'logo_media_asset_id');
    }

    public function isOriginal(): bool
    {
        return $this->parent_media_asset_id === null;
    }
}
