<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\EntityTrust\EntityTrustPresentationCategory;

/**
 * Final merchant-facing outcome for an Entity Trust review/confirm action.
 *
 * Encodes (a) the safe-to-render presentation category, (b) the merchant-safe
 * copy keys, (c) a single opaque reviewFlowId when a successful review was
 * captured on the server, and (d) merchant-safe structured review data for
 * the comparison table.
 *
 * The raw reviewToken NEVER appears here. The raw backend exception NEVER
 * appears here. The remote fingerprint / logical entity id NEVER appears
 * here. Only Product IDs, expected SKUs, merchant-language Magento types,
 * safe media summaries, safe extra-child SKUs and the opaque flow ID are
 * exposed.
 */
final readonly class EntityTrustMerchantOutcome
{
    /**
     * @param  list<EntityTrustMerchantSubjectRow>  $subjects
     * @param  list<string>  $extra_remote_child_skus
     * @param  list<EntityTrustMerchantFieldComparison>  $field_comparisons_sample
     */
    public function __construct(
        public string $product_id,
        public string $productName,
        public ?string $primary_sku,
        public bool $is_configurable_family,
        public EntityTrustFailureReason $reason,
        public EntityTrustPresentationCategory $category,
        public string $label_key,
        public string $explanation_key,
        public string $available_action,
        public ?string $review_flow_id,
        public array $subjects,
        public array $extra_remote_child_skus,
        public bool $extra_remote_children_available,
    ) {}
}
