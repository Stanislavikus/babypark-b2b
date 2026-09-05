<?php

namespace App\Services\Catalog;

use App\Enums\TagBulkOperation;

final readonly class TagBulkMetrics
{
    public function __construct(
        public TagBulkOperation $operation,
        public int $selectedProductCount,
        public int $selectedTagCount,
        public int $changedProductCount,
        public int $unchangedProductCount,
        public int $changedLinkCount,
        public int $noOpLinkCount,
    ) {}
}
