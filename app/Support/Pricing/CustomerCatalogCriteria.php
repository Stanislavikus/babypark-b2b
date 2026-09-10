<?php

namespace App\Support\Pricing;

use App\Enums\CatalogSort;

final readonly class CustomerCatalogCriteria
{
    public function __construct(
        public ?string $search,
        public array $categoryIds,
        public array $brandIds,
        public CatalogSort $sort,
        public int $perPage = 24,
    ) {}

    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>  $brandIds
     */
    public static function fromLegacy(
        ?string $search,
        array $categoryIds,
        array $brandIds,
        string $sortBy,
        string $sortDir,
        int $perPage = 24,
    ): self {
        return new self(
            search: filled($search) ? $search : null,
            categoryIds: $categoryIds,
            brandIds: $brandIds,
            sort: CatalogSort::fromLegacy($sortBy, $sortDir),
            perPage: $perPage,
        );
    }
}
