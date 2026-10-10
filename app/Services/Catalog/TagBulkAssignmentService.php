<?php

namespace App\Services\Catalog;

use App\Enums\TagBulkOperation;
use App\Exceptions\Catalog\InvalidTagBulkSelectionException;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Direct product_tag pivot writes are permitted only inside this service, after complete
 * explicit workspace validation for the entire batch. Every other UI path continues
 * creating/removing tag links through Product::tags()/Tag::products() and ProductTag's guard.
 */
class TagBulkAssignmentService
{
    public function __construct(private readonly int $chunkSize = 100) {}

    /**
     * @param  array<int|string>  $productIds
     * @param  array<int|string>  $tagIds
     */
    public function preview(
        string $workspaceId,
        array $productIds,
        array $tagIds,
        TagBulkOperation $operation,
    ): TagBulkMetrics {
        [$productIds, $tagIds] = $this->normalizeIds($productIds, $tagIds);

        $this->assertSelectionNotEmpty($productIds, $tagIds);
        $this->validateProducts($workspaceId, $productIds, lock: false);
        $this->validateTags($workspaceId, $tagIds, lock: false);

        return $this->processChunkPairs(
            workspaceId: $workspaceId,
            productIds: $productIds,
            tagIds: $tagIds,
            operation: $operation,
            applyWrites: false,
            lockPivotReads: false,
        );
    }

    /**
     * @param  array<int|string>  $productIds
     * @param  array<int|string>  $tagIds
     */
    public function apply(
        string $workspaceId,
        array $productIds,
        array $tagIds,
        TagBulkOperation $operation,
    ): TagBulkMetrics {
        [$productIds, $tagIds] = $this->normalizeIds($productIds, $tagIds);

        $this->assertSelectionNotEmpty($productIds, $tagIds);

        return DB::transaction(function () use ($workspaceId, $productIds, $tagIds, $operation): TagBulkMetrics {
            $this->validateProducts($workspaceId, $productIds, lock: true);
            $this->validateTags($workspaceId, $tagIds, lock: true);

            return $this->processChunkPairs(
                workspaceId: $workspaceId,
                productIds: $productIds,
                tagIds: $tagIds,
                operation: $operation,
                applyWrites: true,
                lockPivotReads: true,
            );
        });
    }

    /**
     * @param  array<int>  $productIds
     * @param  array<string>  $tagIds
     * @return array{0: array<int>, 1: array<string>}
     */
    private function normalizeIds(array $productIds, array $tagIds): array
    {
        $products = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $productIds,
        )));
        sort($products);

        $tags = array_values(array_unique(array_map(
            static fn ($id): string => (string) $id,
            $tagIds,
        )));
        sort($tags);

        return [$products, $tags];
    }

    /**
     * @param  array<int>  $productIds
     * @param  array<string>  $tagIds
     */
    private function assertSelectionNotEmpty(array $productIds, array $tagIds): void
    {
        if ($productIds === []) {
            throw InvalidTagBulkSelectionException::emptyProducts();
        }

        if ($tagIds === []) {
            throw InvalidTagBulkSelectionException::emptyTags();
        }
    }

    /**
     * @param  array<int>  $productIds
     */
    private function validateProducts(string $workspaceId, array $productIds, bool $lock): void
    {
        foreach (array_chunk($productIds, $this->chunkSize) as $chunk) {
            $query = Product::withoutWorkspaceScope()
                ->whereIn('id', $chunk)
                ->orderBy('id');

            if ($lock) {
                $query->lockForUpdate();
            }

            $products = $query->get()->keyBy(static fn (Product $product): int => (int) $product->id);

            foreach ($chunk as $productId) {
                $product = $products->get($productId);

                if ($product === null) {
                    throw InvalidTagBulkSelectionException::productNotFound($productId);
                }

                if ($product->workspace_id !== $workspaceId) {
                    throw InvalidTagBulkSelectionException::productCrossWorkspace($productId);
                }
            }
        }
    }

    /**
     * @param  array<string>  $tagIds
     */
    private function validateTags(string $workspaceId, array $tagIds, bool $lock): void
    {
        foreach (array_chunk($tagIds, $this->chunkSize) as $chunk) {
            $query = Tag::withoutWorkspaceScope()
                ->whereIn('id', $chunk)
                ->orderBy('id');

            if ($lock) {
                $query->lockForUpdate();
            }

            $tags = $query->get()->keyBy(static fn (Tag $tag): string => (string) $tag->id);

            foreach ($chunk as $tagId) {
                $tag = $tags->get($tagId);

                if ($tag === null) {
                    throw InvalidTagBulkSelectionException::tagNotFound($tagId);
                }

                if ($tag->workspace_id !== $workspaceId) {
                    throw InvalidTagBulkSelectionException::tagCrossWorkspace($tagId);
                }
            }
        }
    }

    /**
     * @param  array<int>  $productIds
     * @param  array<string>  $tagIds
     */
    private function processChunkPairs(
        string $workspaceId,
        array $productIds,
        array $tagIds,
        TagBulkOperation $operation,
        bool $applyWrites,
        bool $lockPivotReads,
    ): TagBulkMetrics {
        $changedProductIds = [];
        $changedLinkCount = 0;
        $noOpLinkCount = 0;

        foreach (array_chunk($productIds, $this->chunkSize) as $productChunk) {
            foreach (array_chunk($tagIds, $this->chunkSize) as $tagChunk) {
                $existingPairs = $this->loadExistingPivotPairs(
                    $workspaceId,
                    $productChunk,
                    $tagChunk,
                    $lockPivotReads,
                );

                $rowsToInsert = [];

                foreach ($productChunk as $productId) {
                    foreach ($tagChunk as $tagId) {
                        $pairKey = $this->pairKey($productId, $tagId);
                        $exists = isset($existingPairs[$pairKey]);

                        if ($operation === TagBulkOperation::Add) {
                            if ($exists) {
                                $noOpLinkCount++;
                            } else {
                                $changedLinkCount++;
                                $changedProductIds[(string) $productId] = true;

                                if ($applyWrites) {
                                    $rowsToInsert[] = [
                                        'workspace_id' => $workspaceId,
                                        'product_id' => $productId,
                                        'tag_id' => $tagId,
                                    ];
                                }
                            }

                            continue;
                        }

                        if ($exists) {
                            $changedLinkCount++;
                            $changedProductIds[(string) $productId] = true;
                        } else {
                            $noOpLinkCount++;
                        }
                    }
                }

                if ($applyWrites) {
                    if ($operation === TagBulkOperation::Add && $rowsToInsert !== []) {
                        foreach (array_chunk($rowsToInsert, $this->chunkSize) as $insertChunk) {
                            DB::table('product_tag')->insert($insertChunk);
                        }
                    }

                    if ($operation === TagBulkOperation::Remove) {
                        $expectedDeleted = count($existingPairs);

                        if ($expectedDeleted > 0) {
                            $actualDeleted = $this->deletePivotChunk($workspaceId, $productChunk, $tagChunk);

                            if ($actualDeleted !== $expectedDeleted) {
                                throw new LogicException('Bulk tag delete affected an unexpected number of rows.');
                            }
                        }
                    }
                }
            }
        }

        $selectedProductCount = count($productIds);
        $selectedTagCount = count($tagIds);
        $changedProductCount = count($changedProductIds);

        return new TagBulkMetrics(
            operation: $operation,
            selectedProductCount: $selectedProductCount,
            selectedTagCount: $selectedTagCount,
            changedProductCount: $changedProductCount,
            unchangedProductCount: $selectedProductCount - $changedProductCount,
            changedLinkCount: $changedLinkCount,
            noOpLinkCount: $noOpLinkCount,
        );
    }

    /**
     * @param  array<int>  $productChunk
     * @param  array<string>  $tagChunk
     * @return array<string, true>
     */
    private function loadExistingPivotPairs(
        string $workspaceId,
        array $productChunk,
        array $tagChunk,
        bool $lock,
    ): array {
        $query = DB::table('product_tag')
            ->select(['product_id', 'tag_id'])
            ->where('workspace_id', $workspaceId)
            ->whereIn('product_id', $productChunk)
            ->whereIn('tag_id', $tagChunk)
            ->orderBy('product_id')
            ->orderBy('tag_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $pairs = [];

        foreach ($query->get() as $row) {
            $pairs[$this->pairKey((int) $row->product_id, (string) $row->tag_id)] = true;
        }

        return $pairs;
    }

    /**
     * @param  array<int>  $productChunk
     * @param  array<string>  $tagChunk
     */
    protected function deletePivotChunk(string $workspaceId, array $productChunk, array $tagChunk): int
    {
        return DB::table('product_tag')
            ->where('workspace_id', $workspaceId)
            ->whereIn('product_id', $productChunk)
            ->whereIn('tag_id', $tagChunk)
            ->delete();
    }

    private function pairKey(int $productId, string $tagId): string
    {
        return $productId.':'.$tagId;
    }
}
