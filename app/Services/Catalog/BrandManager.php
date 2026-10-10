<?php

namespace App\Services\Catalog;

use App\Enums\MediaAssetType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BrandManager
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function create(
        User $actor,
        Workspace $workspace,
        string $name,
        ?string $shortDescription = null,
        bool $active = true,
        ?string $logoMediaAssetId = null,
    ): Brand {
        return DB::transaction(function () use ($actor, $workspace, $name, $shortDescription, $active, $logoMediaAssetId): Brand {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $displayName = $this->validatedDisplayName($name);

            if ($this->exactNameQuery($lockedWorkspace, $displayName)->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'Бренд із точно такою назвою вже існує.',
                ]);
            }

            $logoMediaAssetId = $this->validatedLogoMediaAssetId($lockedWorkspace, $logoMediaAssetId);

            return Brand::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'name' => $displayName,
                'logo_media_asset_id' => $logoMediaAssetId,
                'short_description' => $this->nullableTrimmedText($shortDescription),
                'is_active' => $active,
            ]);
        });
    }

    /** @param array{name:mixed,logo_media_asset_id?:mixed,short_description?:mixed,is_active?:mixed} $data */
    public function update(
        User $actor,
        Workspace $workspace,
        Brand $brand,
        array $data,
    ): Brand {
        return DB::transaction(function () use ($actor, $workspace, $brand, $data): Brand {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $lockedBrand = Brand::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($brand->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBrand instanceof Brand) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $displayName = $this->validatedDisplayName((string) ($data['name'] ?? ''));

            if ($displayName !== (string) $lockedBrand->name
                && $this->isUsedBySourceOwnedProduct($lockedWorkspace, $lockedBrand)
            ) {
                throw ValidationException::withMessages([
                    'name' => 'Назву бренду не можна змінити, поки бренд використовується товарами з авторитетним джерелом 1С.',
                ]);
            }

            if ($displayName !== (string) $lockedBrand->name
                && $this->exactNameQuery($lockedWorkspace, $displayName, (string) $lockedBrand->id)->exists()
            ) {
                throw ValidationException::withMessages([
                    'name' => 'Бренд із точно такою назвою вже існує.',
                ]);
            }

            $logoMediaAssetId = array_key_exists('logo_media_asset_id', $data)
                ? $this->validatedLogoMediaAssetId(
                    $lockedWorkspace,
                    filled($data['logo_media_asset_id']) ? (string) $data['logo_media_asset_id'] : null,
                )
                : $lockedBrand->logo_media_asset_id;

            $lockedBrand->update([
                'name' => $displayName,
                'logo_media_asset_id' => $logoMediaAssetId,
                'short_description' => $this->nullableTrimmedText($data['short_description'] ?? $lockedBrand->short_description),
                'is_active' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : (bool) $lockedBrand->is_active,
            ]);

            return $lockedBrand->refresh();
        });
    }

    public function assign(
        User $actor,
        Workspace $workspace,
        Product $product,
        ?string $brandId,
    ): Product {
        return DB::transaction(function () use ($actor, $workspace, $product, $brandId): Product {
            $lockedWorkspace = $this->lockWorkspaceAndAuthorize($actor, $workspace);
            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedProduct instanceof Product) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $brandId = filled($brandId) ? (string) $brandId : null;
            $currentBrandId = filled($lockedProduct->brand_id) ? (string) $lockedProduct->brand_id : null;

            if ($brandId === $currentBrandId) {
                return $lockedProduct->fresh(['brand']);
            }

            if ($this->isSourceOwned($lockedProduct)) {
                throw ValidationException::withMessages([
                    'brand_id' => 'Бренд товару з авторитетним джерелом 1С доступний лише для перегляду.',
                ]);
            }

            if ($brandId !== null) {
                $target = Brand::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->whereKey($brandId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $target instanceof Brand) {
                    throw ValidationException::withMessages([
                        'brand_id' => 'Оберіть активний бренд поточного робочого простору.',
                    ]);
                }
            }

            $lockedProduct->update(['brand_id' => $brandId]);

            return $lockedProduct->fresh(['brand']);
        });
    }

    /** @return array<string, string> */
    public function selectableOptions(string $workspaceId, ?string $currentBrandId = null): array
    {
        return Brand::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($currentBrandId): void {
                $query->where('is_active', true);

                if (is_string($currentBrandId) && $currentBrandId !== '') {
                    $query->orWhere('id', $currentBrandId);
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    private function lockWorkspaceAndAuthorize(User $actor, Workspace $workspace): Workspace
    {
        $lockedWorkspace = Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $lockedWorkspace;
    }

    private function isUsedBySourceOwnedProduct(Workspace $workspace, Brand $brand): bool
    {
        $sourceOwnedProductExists = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('brand_id', $brand->id)
            ->whereNotNull('onec_guid')
            ->exists();

        if ($sourceOwnedProductExists) {
            return true;
        }

        return ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('onec_guid')
            ->whereIn(
                'product_id',
                Product::withoutWorkspaceScope()
                    ->select('id')
                    ->where('workspace_id', $workspace->id)
                    ->where('brand_id', $brand->id),
            )
            ->exists();
    }

    private function isSourceOwned(Product $product): bool
    {
        return filled($product->onec_guid)
            || ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->where('product_id', $product->id)
                ->whereNotNull('onec_guid')
                ->exists();
    }

    private function exactNameQuery(Workspace $workspace, string $name, ?string $excludeBrandId = null): Builder
    {
        $query = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id);

        if (DB::getDriverName() === 'mysql') {
            $query->whereRaw('BINARY name = ?', [$name]);
        } else {
            $query->where('name', $name);
        }

        if ($excludeBrandId !== null) {
            $query->whereKeyNot($excludeBrandId);
        }

        return $query;
    }

    private function validatedDisplayName(string $name): string
    {
        $displayName = trim($name);

        if ($displayName === '') {
            throw ValidationException::withMessages([
                'name' => 'Поле назви обов\'язкове.',
            ]);
        }

        if (mb_strlen($displayName) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Поле назви не може містити більше 255 символів.',
            ]);
        }

        return $displayName;
    }

    private function validatedLogoMediaAssetId(Workspace $workspace, ?string $mediaAssetId): ?string
    {
        if ($mediaAssetId === null || $mediaAssetId === '') {
            return null;
        }

        $asset = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($mediaAssetId)
            ->whereNull('parent_media_asset_id')
            ->where('asset_type', MediaAssetType::Image->value)
            ->lockForUpdate()
            ->first();

        if (! $asset instanceof MediaAsset) {
            throw ValidationException::withMessages([
                'logo_media_asset_id' => 'Оберіть Original зображення поточного робочого простору.',
            ]);
        }

        return (string) $asset->id;
    }

    private function nullableTrimmedText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
