<?php

namespace App\Support\Sync;

final readonly class AdobeProductEffectiveClassification
{
    /**
     * @param  list<string>  $externalCategoryIds
     * @param  list<string>  $blockers
     * @param  list<string>  $advisories
     */
    public function __construct(
        public string $productId,
        public array $externalCategoryIds,
        public string $categorySource,
        public ?string $adobeProductAttributeSetId,
        public ?int $providerAttributeSetId,
        public ?string $attributeSetName,
        public string $attributeSetSource,
        public bool $hasTrustedRemoteSubject,
        public array $blockers = [],
        public array $advisories = [],
    ) {}

    public function isReady(): bool
    {
        return $this->blockers === []
            && $this->externalCategoryIds !== []
            && $this->providerAttributeSetId !== null;
    }

    /**
     * Semantic snapshot form. Labels and internal Adobe observation row IDs are deliberately
     * excluded so cosmetic/provider-catalogue row recreation does not invalidate Preview.
     *
     * @return array{
     *     product_id: string,
     *     category_source: string,
     *     external_category_ids: list<string>,
     *     attribute_set_source: string,
     *     provider_attribute_set_id: ?int,
     *     has_trusted_remote_subject: bool,
     *     blockers: list<string>,
     *     advisories: list<string>
     * }
     */
    public function toSnapshotArray(): array
    {
        return [
            'product_id' => $this->productId,
            'category_source' => $this->categorySource,
            'external_category_ids' => $this->externalCategoryIds,
            'attribute_set_source' => $this->attributeSetSource,
            'provider_attribute_set_id' => $this->providerAttributeSetId,
            'has_trusted_remote_subject' => $this->hasTrustedRemoteSubject,
            'blockers' => $this->blockers,
            'advisories' => $this->advisories,
        ];
    }
}
