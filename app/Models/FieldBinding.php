<?php

namespace App\Models;

use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Support\Workspace\BelongsToWorkspaceOrGlobal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldBinding extends Model
{
    use BelongsToWorkspaceOrGlobal;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'field_definition_id',
        'object_type',
        'storage_type',
        'storage_path',
        'field_group',
        'is_required',
        'is_filterable',
        'is_sortable',
        'visibility_settings',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'object_type' => FieldObjectType::class,
            'storage_type' => AttributeStorageType::class,
            'status' => AttributeStatus::class,
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_sortable' => 'boolean',
            'visibility_settings' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class);
    }

    public function productFieldValues(): HasMany
    {
        return $this->hasMany(ProductFieldValue::class);
    }

    public function variantFieldValues(): HasMany
    {
        return $this->hasMany(VariantFieldValue::class);
    }

    public function customerFieldValues(): HasMany
    {
        return $this->hasMany(CustomerFieldValue::class);
    }

    public function importAliases(): HasMany
    {
        return $this->hasMany(WorkspaceImportAlias::class);
    }
}
