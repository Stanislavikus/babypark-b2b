<?php

namespace App\Services\Catalog;

use App\Enums\MediaAssetType;
use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\OriginalImageIngestService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductMediaException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProductMediaMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductMediaReadService $readService,
        private readonly OriginalImageIngestService $imageIngest,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, ProductMedia>
     */
    public function addUploadedImages(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $files,
    ): Collection {
        $prepared = collect($files)
            ->map(fn (UploadedFile $file) => $this->imageIngest->prepare($file))
            ->all();
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $workspace, $product, $prepared, &$storedPaths): Collection {
                [$lockedWorkspace, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
                $existing = $this->lockedProductMedia($lockedProduct);

                if ($existing->isEmpty() && $this->hasLegacyMediaPayload($lockedProduct)) {
                    throw ProductMediaException::legacyMediaNeedsRepair();
                }

                $nextOrder = $existing->isEmpty() ? 0 : ((int) $existing->max('sort_order')) + 1;

                foreach ($prepared as $item) {
                    $ingested = $this->imageIngest->ingestPrepared($actor, $lockedWorkspace, $item);
                    $asset = $ingested->asset;

                    if ($ingested->newStoredPath !== null) {
                        $storedPaths[] = $ingested->newStoredPath;
                    }

                    if (ProductMedia::withoutWorkspaceScope()
                        ->where('workspace_id', $lockedWorkspace->id)
                        ->where('product_id', $lockedProduct->id)
                        ->where('media_asset_id', $asset->id)
                        ->exists()) {
                        continue;
                    }

                    ProductMedia::withoutWorkspaceScope()->create([
                        'workspace_id' => $lockedWorkspace->id,
                        'product_id' => $lockedProduct->id,
                        'media_asset_id' => $asset->id,
                        'role' => $nextOrder === 0 ? MediaRole::Primary : MediaRole::Gallery,
                        'sort_order' => $nextOrder,
                        'locale' => null,
                    ]);
                    $nextOrder++;
                }

                $this->syncLegacyProjection($lockedProduct);

                return $this->readService->productMedia($lockedProduct);
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $stored) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }

            throw $e;
        }
    }

    /**
     * Reuse canonical same-workspace Originals in the Product gallery without
     * copying their bytes or changing shared Asset identity.
     *
     * @param  list<string>  $assetIds
     * @return Collection<int, ProductMedia>
     */
    public function addExistingImages(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $assetIds,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $assetIds): Collection {
            [$lockedWorkspace, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $existing = $this->lockedProductMedia($lockedProduct);

            if ($existing->isEmpty() && $this->hasLegacyMediaPayload($lockedProduct)) {
                throw ProductMediaException::legacyMediaNeedsRepair();
            }

            $nextOrder = $existing->isEmpty() ? 0 : ((int) $existing->max('sort_order')) + 1;
            $alreadyLinked = $existing->pluck('media_asset_id')->map(fn ($id): string => (string) $id)->all();

            foreach (array_unique($assetIds) as $assetId) {
                if (! is_string($assetId) || $assetId === '') {
                    throw ProductMediaException::mediaNotFound();
                }

                $asset = MediaAsset::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->whereNull('parent_media_asset_id')
                    ->where('asset_type', MediaAssetType::Image->value)
                    ->whereKey($assetId)
                    ->lockForUpdate()
                    ->first();

                if (! $asset instanceof MediaAsset) {
                    throw ProductMediaException::mediaNotFound();
                }

                if (in_array((string) $asset->id, $alreadyLinked, true)) {
                    continue;
                }

                ProductMedia::withoutWorkspaceScope()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'product_id' => $lockedProduct->id,
                    'media_asset_id' => $asset->id,
                    'role' => $nextOrder === 0 ? MediaRole::Primary : MediaRole::Gallery,
                    'sort_order' => $nextOrder,
                    'locale' => null,
                ]);
                $alreadyLinked[] = (string) $asset->id;
                $nextOrder++;
            }

            $this->syncLegacyProjection($lockedProduct);

            return $this->readService->productMedia($lockedProduct);
        });
    }

    /**
     * Reorder the full Product gallery. The first row becomes the Master primary.
     *
     * @param  list<string>  $productMediaIds
     * @return Collection<int, ProductMedia>
     */
    public function reorder(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $productMediaIds,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $productMediaIds): Collection {
            [, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $media = $this->lockedProductMedia($lockedProduct);
            $currentIds = $media->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
            $requested = array_values(array_unique(array_map('strval', $productMediaIds)));
            $requestedSorted = $requested;
            sort($requestedSorted);

            if ($currentIds !== $requestedSorted) {
                throw ProductMediaException::incompleteOrder();
            }

            if ($media->isEmpty()) {
                $this->syncLegacyProjection($lockedProduct);

                return $media;
            }

            ProductMedia::withoutWorkspaceScope()
                ->where('workspace_id', $lockedProduct->workspace_id)
                ->where('product_id', $lockedProduct->id)
                ->whereNull('locale')
                ->update([
                    'role' => MediaRole::Gallery->value,
                    'sort_order' => DB::raw('sort_order + 1000000'),
                ]);

            foreach ($requested as $index => $mediaId) {
                ProductMedia::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedProduct->workspace_id)
                    ->where('product_id', $lockedProduct->id)
                    ->whereNull('locale')
                    ->whereKey($mediaId)
                    ->update([
                        'role' => $index === 0 ? MediaRole::Primary->value : MediaRole::Gallery->value,
                        'sort_order' => $index,
                        'updated_at' => now(),
                    ]);
            }

            $this->syncLegacyProjection($lockedProduct);

            return $this->readService->productMedia($lockedProduct);
        });
    }

    /**
     * @return Collection<int, ProductMedia>
     */
    public function makePrimary(
        User $actor,
        Workspace $workspace,
        Product $product,
        string $productMediaId,
    ): Collection {
        $current = $this->readService->productMedia($product);
        $target = $current->firstWhere('id', $productMediaId);

        if (! $target instanceof ProductMedia) {
            throw ProductMediaException::mediaNotFound();
        }

        $ids = $current->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $ids = array_values(array_filter($ids, fn (string $id): bool => $id !== $productMediaId));
        array_unshift($ids, $productMediaId);

        return $this->reorder($actor, $workspace, $product, $ids);
    }

    /**
     * @return Collection<int, ProductMedia>
     */
    public function remove(
        User $actor,
        Workspace $workspace,
        Product $product,
        string $productMediaId,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $productMediaId): Collection {
            [, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $media = $this->lockedProductMedia($lockedProduct);
            $target = $media->firstWhere('id', $productMediaId);

            if (! $target instanceof ProductMedia) {
                throw ProductMediaException::mediaNotFound();
            }

            $target->delete();
            $remaining = ProductMedia::withoutWorkspaceScope()
                ->where('workspace_id', $lockedProduct->workspace_id)
                ->where('product_id', $lockedProduct->id)
                ->whereNull('locale')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($remaining->isNotEmpty()) {
                $this->normalizeOrderAndPrimary($lockedProduct, $remaining->pluck('id')->map(fn ($id): string => (string) $id)->all());
            }

            $this->syncLegacyProjection($lockedProduct);

            return $this->readService->productMedia($lockedProduct);
        });
    }

    /** @return array{0:Workspace,1:Product} */
    private function lockContext(User $actor, Workspace $workspace, Product $product): array
    {
        $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $lockedProduct = Product::withoutWorkspaceScope()
            ->where('workspace_id', $lockedWorkspace->id)
            ->whereKey($product->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$lockedWorkspace, $lockedProduct];
    }

    /**
     * @return Collection<int, ProductMedia>
     */
    private function lockedProductMedia(Product $product): Collection
    {
        return ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param list<string> $ids */
    private function normalizeOrderAndPrimary(Product $product, array $ids): void
    {
        ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->update([
                'role' => MediaRole::Gallery->value,
                'sort_order' => DB::raw('sort_order + 1000000'),
            ]);

        foreach ($ids as $index => $mediaId) {
            ProductMedia::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->where('product_id', $product->id)
                ->whereNull('locale')
                ->whereKey($mediaId)
                ->update([
                    'role' => $index === 0 ? MediaRole::Primary->value : MediaRole::Gallery->value,
                    'sort_order' => $index,
                    'updated_at' => now(),
                ]);
        }
    }

    private function hasLegacyMediaPayload(Product $product): bool
    {
        $images = $product->images;

        if ($images === null) {
            return false;
        }

        if (is_array($images)) {
            return $images !== [];
        }

        return filled($images);
    }

    private function syncLegacyProjection(Product $product): void
    {
        $references = $this->readService->firstClassSourceReferences($product) ?? [];
        $projection = collect($references)
            ->filter(fn ($reference): bool => is_string($reference) && trim($reference) !== '')
            ->map(fn (string $reference): string => trim($reference))
            ->values()
            ->all();

        $product->forceFill(['images' => $projection])->saveQuietly();
    }
}
