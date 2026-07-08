<?php

namespace App\Models;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\AttributeValueLevel;
use App\Support\Workspace\BelongsToWorkspaceOrGlobal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeDefinition extends Model
{
    use BelongsToWorkspaceOrGlobal;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'code',
        'data_type',
        'scope',
        'value_level',
        'storage_type',
        'storage_path',
        'attribute_group',
        'is_required',
        'is_filterable',
        'is_sortable',
        'visibility_settings',
        'validation_rules',
        'is_localizable',
        'is_multi_value',
        'status',
        'sort_order',
        'localized_labels',
    ];

    protected function casts(): array
    {
        return [
            'data_type' => AttributeDataType::class,
            'scope' => AttributeScope::class,
            'value_level' => AttributeValueLevel::class,
            'storage_type' => AttributeStorageType::class,
            'status' => AttributeStatus::class,
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_sortable' => 'boolean',
            'visibility_settings' => 'array',
            'validation_rules' => 'array',
            'is_localizable' => 'boolean',
            'is_multi_value' => 'boolean',
            'sort_order' => 'integer',
            'localized_labels' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function productAttributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function variantAttributeValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class);
    }

    public function importAliases(): HasMany
    {
        return $this->hasMany(WorkspaceImportAlias::class);
    }

    public function localizedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $labels = $this->localized_labels ?? [];

        return $labels[$locale] ?? $labels['uk'] ?? $this->code;
    }

    public function visibilityRestricted(): bool
    {
        return $this->scope === AttributeScope::System
            && $this->code === 'internal_product_id';
    }
}
