<?php

namespace App\Support\Sync\EntityTrust;

final readonly class EntityTrustMerchantSubjectRow
{
    public function __construct(
        public string $role,                // "parent" | "variant"
        public string $subject_key,
        public string $expected_sku,
        public string $magento_type_label,   // merchant-language "Простий товар" / "Конфігурований товар"
        public ?string $platform_name,
        public ?int $declared_image_count,
        public ?string $declared_roles_summary,
        /**
         * @var list<EntityTrustMerchantFieldComparison>
         */
        public array $field_comparisons,
    ) {}
}
