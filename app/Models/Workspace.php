<?php

namespace App\Models;

use App\Enums\PriceDisplayMode;
use App\Services\ProductStructure\BasicProductStructureReconciler;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'is_default',
        'default_vat_rate',
        'default_price_display_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'default_vat_rate' => 'decimal:2',
            'default_price_display_mode' => PriceDisplayMode::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            $workspace->default_vat_rate ??= (string) config('pricing.default_vat_rate', 20);
        });

        static::created(function (Workspace $workspace): void {
            $reconciler = app(BasicProductStructureReconciler::class);
            if ($reconciler->available()) {
                $reconciler->reconcileWorkspace((string) $workspace->id);
            }
        });
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(ProductType::class);
    }

    public function attributeGroups(): HasMany
    {
        return $this->hasMany(AttributeGroup::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function workspaceUsers(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function workspaceRoles(): HasMany
    {
        return $this->hasMany(WorkspaceRole::class);
    }
}
