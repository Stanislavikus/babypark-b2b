<?php

namespace App\Services\Catalog;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\VariantMediaException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class VariantMediaMutationService
{
    private const TEMP_ORDER_OFFSET = 1000000;

    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /**
     * @param  list<int|string>  $variantIds
     * @param  list<string>  $mediaAssetIds
     * @return Collection<int, VariantMedia>
     */
    public function assign(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $variantIds,
        array $mediaAssetIds,
        bool $makeFirstSelectedPrimary = false,
    ): Collection {
        return DB::transaction(function () use (
            $actor,
            $workspace,
            $product,
            $variantIds,
            $mediaAssetIds,
            $makeFirstSelectedPrimary,
        ): Collection {
            [$lockedProduct, $variants] = $this->lockProductAndVariants($actor, $workspace, $product, $variantIds);
            $assetIds = $this->lockedOriginalAssetIds($lockedProduct->workspace_id, $mediaAssetIds);

            if ($assetIds === []) {
                throw VariantMediaException::assetUnavailable();
            }

            foreach ($variants as $variant) {
                $current = $this->lockedVariantMedia($variant);
                $existingByAsset = $current->keyBy(fn (VariantMedia $row): string => (string) $row->media_asset_id);
                $nextOrder = $current->isEmpty() ? 0 : ((int) $current->max('sort_order')) + 1;

                foreach ($assetIds as $assetId) {
                    if ($existingByAsset->has($assetId)) {
                        continue;
                    }

                    $row = VariantMedia::withoutWorkspaceScope()->create([
                        'workspace_id' => $variant->workspace_id,
                        'variant_id' => $variant->id,
                        'media_asset_id' => $assetId,
                        'role' => MediaRole::Gallery,
                        'sort_order' => $nextOrder++,
                        'locale' => null,
                    ]);
                    $existingByAsset->put($assetId, $row);
                }

                if ($makeFirstSelectedPrimary) {
                    $this->makeAssetPrimaryLocked($variant, $assetIds[0]);
                }
            }

            return $this->rowsForVariants((string) $lockedProduct->workspace_id, $variants->pluck('id')->all());
        });
    }

    /**
     * Replace each selected Variant's own common-scope gallery with the selected assets.
     * Existing association UUIDs are preserved for assets that remain selected.
     *
     * @param  list<int|string>  $variantIds
     * @param  list<string>  $mediaAssetIds
     * @return Collection<int, VariantMedia>
     */
    public function replace(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $variantIds,
        array $mediaAssetIds,
        bool $makeFirstSelectedPrimary = false,
    ): Collection {
        return DB::transaction(function () use (
            $actor,
            $workspace,
            $product,
            $variantIds,
            $mediaAssetIds,
            $makeFirstSelectedPrimary,
        ): Collection {
            [$lockedProduct, $variants] = $this->lockProductAndVariants($actor, $workspace, $product, $variantIds);
            $assetIds = $this->lockedOriginalAssetIds($lockedProduct->workspace_id, $mediaAssetIds);

            foreach ($variants as $variant) {
                $current = $this->lockedVariantMedia($variant);
                /** @var VariantMedia|null $currentPrimary */
                $currentPrimary = $current->first(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary);
                $currentPrimaryAssetId = $currentPrimary?->media_asset_id;

                if ($assetIds === []) {
                    VariantMedia::withoutWorkspaceScope()
                        ->where('workspace_id', $variant->workspace_id)
                        ->where('variant_id', $variant->id)
                        ->whereNull('locale')
                        ->delete();

                    continue;
                }

                $desiredLookup = array_fill_keys($assetIds, true);

                VariantMedia::withoutWorkspaceScope()
                    ->where('workspace_id', $variant->workspace_id)
                    ->where('variant_id', $variant->id)
                    ->whereNull('locale')
                    ->whereNotIn('media_asset_id', $assetIds)
                    ->delete();

                $remaining = $this->lockedVariantMedia($variant);
                $byAsset = $remaining->keyBy(fn (VariantMedia $row): string => (string) $row->media_asset_id);
                $tempOrder = ($remaining->isEmpty() ? 0 : (int) $remaining->max('sort_order')) + (self::TEMP_ORDER_OFFSET * 2);

                foreach ($assetIds as $assetId) {
                    if ($byAsset->has($assetId)) {
                        continue;
                    }

                    $row = VariantMedia::withoutWorkspaceScope()->create([
                        'workspace_id' => $variant->workspace_id,
                        'variant_id' => $variant->id,
                        'media_asset_id' => $assetId,
                        'role' => MediaRole::Gallery,
                        'sort_order' => $tempOrder++,
                        'locale' => null,
                    ]);
                    $byAsset->put($assetId, $row);
                }

                $primaryAssetId = null;
                if ($makeFirstSelectedPrimary) {
                    $primaryAssetId = $assetIds[0];
                } elseif (is_string($currentPrimaryAssetId) && isset($desiredLookup[$currentPrimaryAssetId])) {
                    $primaryAssetId = $currentPrimaryAssetId;
                }

                $orderedAssetIds = $assetIds;
                if ($primaryAssetId !== null) {
                    $orderedAssetIds = array_values(array_filter(
                        $orderedAssetIds,
                        fn (string $assetId): bool => $assetId !== $primaryAssetId,
                    ));
                    array_unshift($orderedAssetIds, $primaryAssetId);
                }

                $associationIds = array_map(
                    fn (string $assetId): string => (string) $byAsset->get($assetId)->id,
                    $orderedAssetIds,
                );
                $primaryAssociationId = $primaryAssetId !== null
                    ? (string) $byAsset->get($primaryAssetId)->id
                    : null;

                $this->rewriteOrderAndPrimary($variant, $associationIds, $primaryAssociationId);
            }

            return $this->rowsForVariants((string) $lockedProduct->workspace_id, $variants->pluck('id')->all());
        });
    }

    /**
     * @param  list<int|string>  $variantIds
     * @param  list<string>  $mediaAssetIds
     * @return Collection<int, VariantMedia>
     */
    public function detach(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $variantIds,
        array $mediaAssetIds,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $variantIds, $mediaAssetIds): Collection {
            [$lockedProduct, $variants] = $this->lockProductAndVariants($actor, $workspace, $product, $variantIds);
            $assetIds = $this->normalizeAssetIds($mediaAssetIds);

            foreach ($variants as $variant) {
                $current = $this->lockedVariantMedia($variant);
                /** @var VariantMedia|null $primary */
                $primary = $current->first(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary);
                $removedPrimary = $primary instanceof VariantMedia
                    && in_array((string) $primary->media_asset_id, $assetIds, true);

                VariantMedia::withoutWorkspaceScope()
                    ->where('workspace_id', $variant->workspace_id)
                    ->where('variant_id', $variant->id)
                    ->whereNull('locale')
                    ->whereIn('media_asset_id', $assetIds)
                    ->delete();

                $remaining = $this->lockedVariantMedia($variant);
                if ($remaining->isEmpty()) {
                    continue;
                }

                $associationIds = $remaining->pluck('id')->map(fn ($id): string => (string) $id)->all();
                $primaryAssociationId = $removedPrimary ? null : ($primary?->id !== null ? (string) $primary->id : null);

                $this->rewriteOrderAndPrimary($variant, $associationIds, $primaryAssociationId);
            }

            return $this->rowsForVariants((string) $lockedProduct->workspace_id, $variants->pluck('id')->all());
        });
    }

    /**
     * @param  list<string>  $variantMediaIds
     * @return Collection<int, VariantMedia>
     */
    public function reorder(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductVariant $variant,
        array $variantMediaIds,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $variant, $variantMediaIds): Collection {
            [, $variants] = $this->lockProductAndVariants($actor, $workspace, $product, [$variant->id]);
            /** @var ProductVariant $lockedVariant */
            $lockedVariant = $variants->first();
            $current = $this->lockedVariantMedia($lockedVariant);

            $requested = array_values(array_unique(array_map('strval', $variantMediaIds)));
            $currentIds = $current->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
            $requestedSorted = $requested;
            sort($requestedSorted);

            if ($currentIds !== $requestedSorted) {
                throw VariantMediaException::incompleteOrder();
            }

            /** @var VariantMedia|null $primary */
            $primary = $current->first(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary);
            if ($primary instanceof VariantMedia && ($requested[0] ?? null) !== (string) $primary->id) {
                throw VariantMediaException::explicitPrimaryRequired();
            }

            $this->rewriteOrderAndPrimary(
                $lockedVariant,
                $requested,
                $primary instanceof VariantMedia ? (string) $primary->id : null,
            );

            return $this->lockedVariantMedia($lockedVariant);
        });
    }

    /**
     * @return Collection<int, VariantMedia>
     */
    public function makePrimary(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductVariant $variant,
        string $variantMediaId,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $variant, $variantMediaId): Collection {
            [, $variants] = $this->lockProductAndVariants($actor, $workspace, $product, [$variant->id]);
            /** @var ProductVariant $lockedVariant */
            $lockedVariant = $variants->first();
            $current = $this->lockedVariantMedia($lockedVariant);
            $target = $current->firstWhere('id', $variantMediaId);

            if (! $target instanceof VariantMedia) {
                throw VariantMediaException::mediaNotFound();
            }

            $associationIds = $current->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $associationIds = array_values(array_filter(
                $associationIds,
                fn (string $id): bool => $id !== (string) $target->id,
            ));
            array_unshift($associationIds, (string) $target->id);

            $this->rewriteOrderAndPrimary($lockedVariant, $associationIds, (string) $target->id);

            return $this->lockedVariantMedia($lockedVariant);
        });
    }

    /**
     * @param  list<int|string>  $variantIds
     * @return array{0:Product,1:Collection<int,ProductVariant>}
     */
    private function lockProductAndVariants(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $variantIds,
    ): array {
        $lockedWorkspace = Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $lockedProduct = Product::withoutWorkspaceScope()
            ->where('workspace_id', $lockedWorkspace->id)
            ->whereKey($product->id)
            ->lockForUpdate()
            ->firstOrFail();

        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        if ($ids === []) {
            throw VariantMediaException::variantUnavailable();
        }

        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $lockedWorkspace->id)
            ->where('product_id', $lockedProduct->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($variants->count() !== count($ids)) {
            throw VariantMediaException::variantUnavailable();
        }

        return [$lockedProduct, $variants];
    }

    /**
     * @param  list<string>  $mediaAssetIds
     * @return list<string>
     */
    private function lockedOriginalAssetIds(string $workspaceId, array $mediaAssetIds): array
    {
        $ids = $this->normalizeAssetIds($mediaAssetIds);
        if ($ids === []) {
            return [];
        }

        $assets = MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MediaAsset $asset): string => (string) $asset->id);

        if ($assets->count() !== count($ids)) {
            throw VariantMediaException::assetUnavailable();
        }

        foreach ($ids as $id) {
            $asset = $assets->get($id);
            if (! $asset instanceof MediaAsset || ! $asset->isOriginal()) {
                throw VariantMediaException::invalidOriginal();
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $mediaAssetIds
     * @return list<string>
     */
    private function normalizeAssetIds(array $mediaAssetIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($id): string => trim((string) $id), $mediaAssetIds),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * @return Collection<int, VariantMedia>
     */
    private function lockedVariantMedia(ProductVariant $variant): Collection
    {
        return VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('variant_id', $variant->id)
            ->whereNull('locale')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function makeAssetPrimaryLocked(ProductVariant $variant, string $mediaAssetId): void
    {
        $current = $this->lockedVariantMedia($variant);
        $target = $current->firstWhere('media_asset_id', $mediaAssetId);

        if (! $target instanceof VariantMedia) {
            throw VariantMediaException::mediaNotFound();
        }

        $ids = $current->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $ids = array_values(array_filter($ids, fn (string $id): bool => $id !== (string) $target->id));
        array_unshift($ids, (string) $target->id);

        $this->rewriteOrderAndPrimary($variant, $ids, (string) $target->id);
    }

    /**
     * @param  list<string>  $associationIds
     */
    private function rewriteOrderAndPrimary(
        ProductVariant $variant,
        array $associationIds,
        ?string $primaryAssociationId,
    ): void {
        VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('variant_id', $variant->id)
            ->whereNull('locale')
            ->update([
                'role' => MediaRole::Gallery->value,
                'sort_order' => DB::raw('sort_order + '.self::TEMP_ORDER_OFFSET),
            ]);

        foreach ($associationIds as $index => $associationId) {
            VariantMedia::withoutWorkspaceScope()
                ->where('workspace_id', $variant->workspace_id)
                ->where('variant_id', $variant->id)
                ->whereNull('locale')
                ->whereKey($associationId)
                ->update([
                    'role' => $primaryAssociationId !== null && $associationId === $primaryAssociationId
                        ? MediaRole::Primary->value
                        : MediaRole::Gallery->value,
                    'sort_order' => $index,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  list<int|string>  $variantIds
     * @return Collection<int, VariantMedia>
     */
    private function rowsForVariants(string $workspaceId, array $variantIds): Collection
    {
        return VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('variant_id', $variantIds)
            ->whereNull('locale')
            ->with(['asset' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('variant_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
