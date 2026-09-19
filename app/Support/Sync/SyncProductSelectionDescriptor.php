<?php

namespace App\Support\Sync;

final readonly class SyncProductSelectionDescriptor
{
    public const MODE = 'explicit_products';

    /**
     * @param  list<int>  $productIds
     */
    private function __construct(
        public array $productIds,
        public int $productCount,
        public string $productIdsHash,
    ) {}

    /**
     * @param  iterable<int|string>  $productIds
     */
    public static function fromProductIds(iterable $productIds): self
    {
        $canonical = [];

        foreach ($productIds as $productId) {
            $id = filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                throw new \InvalidArgumentException('Product selection ids must be positive integers.');
            }

            $canonical[(int) $id] = true;
        }

        $ids = array_keys($canonical);
        sort($ids, SORT_NUMERIC);

        return new self(
            productIds: $ids,
            productCount: count($ids),
            productIdsHash: hash('sha256', implode("\n", $ids)),
        );
    }

    public static function empty(): self
    {
        return self::fromProductIds([]);
    }

    /** @return array{mode: string, product_count: int, product_ids_hash: string} */
    public function toRevisionArray(): array
    {
        return [
            'mode' => self::MODE,
            'product_count' => $this->productCount,
            'product_ids_hash' => $this->productIdsHash,
        ];
    }

    /** @return array{mode: string, product_count: int, product_ids_hash: string} */
    public function toSnapshotArray(): array
    {
        return $this->toRevisionArray();
    }

    public function matchesSnapshot(mixed $snapshot): bool
    {
        return is_array($snapshot) && $snapshot === $this->toSnapshotArray();
    }
}
