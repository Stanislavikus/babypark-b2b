<?php

namespace App\Services\Catalog;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductMediaException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductMediaMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductMediaReadService $readService,
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
        $prepared = collect($files)->map(fn (UploadedFile $file): array => $this->diagnoseUpload($file))->all();
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
                    /** @var UploadedFile $file */
                    $file = $item['file'];
                    $asset = MediaAsset::withoutWorkspaceScope()
                        ->where('workspace_id', $lockedWorkspace->id)
                        ->where('content_sha256', $item['sha256'])
                        ->lockForUpdate()
                        ->first();

                    if ($asset instanceof MediaAsset && ! $asset->isOriginal()) {
                        throw ProductMediaException::invalidOriginal();
                    }

                    if (! $asset instanceof MediaAsset) {
                        $assetId = (string) Str::uuid();
                        $storagePath = $file->storeAs(
                            'media/originals/'.$lockedWorkspace->id,
                            $assetId.'.'.$item['extension'],
                            ['disk' => 'public'],
                        );

                        if (! is_string($storagePath) || $storagePath === '') {
                            throw new ProductMediaException('Не вдалося зберегти Original медіафайлу.');
                        }

                        $storedPaths[] = ['disk' => 'public', 'path' => $storagePath];

                        $asset = MediaAsset::withoutWorkspaceScope()->create([
                            'id' => $assetId,
                            'workspace_id' => $lockedWorkspace->id,
                            'parent_media_asset_id' => null,
                            'asset_type' => MediaAssetType::Image,
                            'storage_disk' => 'public',
                            'storage_path' => $storagePath,
                            'source_url' => null,
                            'original_filename' => $file->getClientOriginalName(),
                            'mime_type' => $item['mime_type'],
                            'byte_size' => $item['byte_size'],
                            'content_sha256' => $item['sha256'],
                            'width_px' => $item['width_px'],
                            'height_px' => $item['height_px'],
                            'diagnosis_status' => MediaDiagnosisStatus::Ready,
                            'diagnosis_json' => [
                                'native_width_px' => $item['width_px'],
                                'native_height_px' => $item['height_px'],
                                'mime_type' => $item['mime_type'],
                                'byte_size' => $item['byte_size'],
                            ],
                            'provenance_json' => [
                                'kind' => 'merchant_upload',
                                'actor_user_id' => (string) $actor->id,
                            ],
                        ]);
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

    /**
     * @return array{file:UploadedFile,mime_type:string,extension:string,byte_size:int,sha256:string,width_px:int,height_px:int}
     */
    private function diagnoseUpload(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw ProductMediaException::invalidImage();
        }

        $info = @getimagesize($path);
        if ($info === false || ! isset($info[0], $info[1], $info['mime'])) {
            throw ProductMediaException::invalidImage();
        }

        $mimeType = strtolower((string) $info['mime']);
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => throw ProductMediaException::invalidImage(),
        };

        $byteSize = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if (! is_int($byteSize) || ! is_string($sha256) || $sha256 === '') {
            throw ProductMediaException::invalidImage();
        }

        return [
            'file' => $file,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'byte_size' => $byteSize,
            'sha256' => $sha256,
            'width_px' => (int) $info[0],
            'height_px' => (int) $info[1],
        ];
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
