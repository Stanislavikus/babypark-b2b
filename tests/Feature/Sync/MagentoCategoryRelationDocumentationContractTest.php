<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagentoCategoryRelationDocumentationContractTest extends TestCase
{
    #[Test]
    public function p01_is_closed_with_granular_global_relation_semantics(): void
    {
        $ledger = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md'));

        $this->assertStringContainsString(
            'P-01 — Category relation runtime — CLOSED 2026-09-18 [Resolved]',
            $ledger,
        );
        $this->assertStringContainsString('CategoryLinkRepositoryInterface::save/deleteByIds', $ledger);
        $this->assertStringContainsString('uses `/rest/all` only for granular category membership POST/DELETE', $ledger);
        $this->assertStringContainsString('never adopted as platform-owned', $ledger);
        $this->assertStringContainsString('Ambiguous ADD becomes `add_ambiguous`', $ledger);
        $this->assertStringContainsString('many local categories may intentionally collapse to one external category', $ledger);
    }

    #[Test]
    public function domain_model_preserves_all_prohibition_except_for_category_membership_owner(): void
    {
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '[Resolved — P-01 Category relation global-context amendment — 2026-09-18]',
            $domain,
        );
        $this->assertStringContainsString('The `all` prohibition above continues to govern Product value / media / localized', $domain);
        $this->assertStringContainsString('`/rest/all`', $domain);
        $this->assertStringContainsString('does not authorize whole-array category', $domain);
    }

    #[Test]
    public function product_field_matrix_reports_partial_category_group_truth(): void
    {
        $matrix = json_decode(
            File::get(base_path('docs/connectors/adobe-commerce/magento_v1_product_field_matrix.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $row = collect($matrix['rows'] ?? $matrix)->firstWhere('id', 'category-bindings');

        $this->assertIsArray($row);
        $this->assertSame('PARTIAL', $row['read_capability_state']);
        $this->assertSame('PARTIAL', $row['write_capability_state']);
        $this->assertSame(
            'real_target_category_relation_write_read_restore_verified_2026_09_18',
            $row['real_validation_state'],
        );
        $this->assertSame(
            'category_relation_runtime_verified_rewrite_flag_pending',
            $row['field_certification_status'],
        );
    }
}
