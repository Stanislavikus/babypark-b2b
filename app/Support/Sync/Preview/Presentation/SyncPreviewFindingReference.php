<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Enums\SyncPreviewFindingCode;

final readonly class SyncPreviewFindingReference
{
    public function __construct(
        public ?SyncPreviewFindingCode $code,
        public ?string $fieldBindingId,
        public ?string $variantId,
        public ?string $productId,
        public ?string $internalOptionKey,
        public ?string $externalFieldKey,
        public ?string $externalOptionValue,
        public bool $showsVariantContext,
    ) {}
}
