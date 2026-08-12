<?php

namespace App\Models;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Support\Sync\Exceptions\FieldDefinitionReferencedByFieldMappingException;
use App\Support\Workspace\BelongsToWorkspaceOrGlobal;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldDefinition extends Model
{
    use BelongsToWorkspaceOrGlobal;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'code',
        'data_type',
        'scope',
        'localized_labels',
        'description',
        'validation_rules',
        'is_localizable',
        'is_multi_value',
        'status',
    ];

    protected static function booted(): void
    {
        static::deleting(function (FieldDefinition $definition): void {
            $bindingIds = $definition->fieldBindings()->pluck('id');

            if ($bindingIds->isEmpty()) {
                return;
            }

            if (FieldMapping::withoutWorkspaceScope()->whereIn('field_binding_id', $bindingIds)->exists()) {
                throw FieldDefinitionReferencedByFieldMappingException::forDefinition($definition->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'data_type' => AttributeDataType::class,
            'scope' => AttributeScope::class,
            'status' => AttributeStatus::class,
            'localized_labels' => 'array',
            'validation_rules' => 'array',
            'is_localizable' => 'boolean',
            'is_multi_value' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fieldBindings(): HasMany
    {
        return $this->hasMany(FieldBinding::class);
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

    public function computedValueLevelLabel(): string
    {
        $types = $this->fieldBindings
            ->pluck('object_type')
            ->map(fn ($type) => $type instanceof FieldObjectType ? $type->value : (string) $type)
            ->unique()
            ->values();

        $hasProduct = $types->contains('product');
        $hasVariant = $types->contains('product_variant');

        if ($hasProduct && $hasVariant) {
            return 'Обидва';
        }

        if ($hasProduct) {
            return 'Товар';
        }

        if ($hasVariant) {
            return 'Варіант';
        }

        return '—';
    }
}
