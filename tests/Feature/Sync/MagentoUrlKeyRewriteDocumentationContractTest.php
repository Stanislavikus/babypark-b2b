<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagentoUrlKeyRewriteDocumentationContractTest extends TestCase
{
    #[Test]
    public function p02_is_closed_with_routing_specific_fail_closed_contract(): void
    {
        $ledger = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md'));

        $this->assertStringContainsString(
            'P-02 — `url_key` / URL rewrite side effects — CLOSED 2026-09-19 [Resolved]',
            $ledger,
        );
        $this->assertStringContainsString('Generic FieldMapping WRITE/CLEAR is blocked', $ledger);
        $this->assertStringContainsString('Empty/null/reset `url_key` is unsupported', $ledger);
        $this->assertStringContainsString('Magento owns generated canonical/category rewrites and redirect history', $ledger);
        $this->assertStringContainsString('Configurable Product-level routing values are not projected into `simple_child`', $ledger);
        $this->assertStringContainsString('magento_v1_url_key_rewrite_certification_2026_09_19.json', $ledger);
    }

    #[Test]
    public function p02_evidence_proves_two_cycles_collision_and_exact_restore(): void
    {
        $evidence = json_decode(
            File::get(base_path('docs/connectors/adobe-commerce/magento_v1_url_key_rewrite_certification_2026_09_19.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('PASS', $evidence['result']);
        $this->assertTrue($evidence['conclusion']['p02_cleared']);
        $this->assertFalse($evidence['conclusion']['merchant_mapping_opened']);
        $this->assertFalse($evidence['conclusion']['canonical_slug_materialized']);
        $this->assertCount(2, $evidence['products']);

        foreach ($evidence['products'] as $product) {
            $this->assertSame('known_applied', $product['change']['result']['state']);
            $this->assertSame('stock_write_verified', $product['change']['result']['reason']);
            $this->assertSame(1, $product['change']['result']['writes']);
            $this->assertSame(1, $product['change']['result']['gets']);
            $this->assertSame('known_applied', $product['restore']['result']['state']);
            $this->assertSame('stock_write_verified', $product['restore']['result']['reason']);
            $this->assertSame(1, $product['restore']['result']['writes']);
            $this->assertSame(1, $product['restore']['result']['gets']);
        }

        $this->assertTrue($evidence['collision']['postcondition']['subject_url_key_unchanged']);
        $this->assertTrue($evidence['collision']['postcondition']['owner_url_key_unchanged']);
        $this->assertTrue($evidence['collision']['postcondition']['subject_route_unchanged']);
        $this->assertTrue($evidence['collision']['postcondition']['owner_route_unchanged']);
        $this->assertSame(
            $evidence['products'][0]['baseline_url_key'],
            $evidence['final_independent_reads']['joolz']['url_key'],
        );
        $this->assertSame(
            $evidence['products'][1]['baseline_url_key'],
            $evidence['final_independent_reads']['layla']['url_key'],
        );
    }

    #[Test]
    public function product_field_matrix_keeps_routing_support_partial_and_explicit(): void
    {
        $matrix = json_decode(
            File::get(base_path('docs/connectors/adobe-commerce/magento_v1_product_field_matrix.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $row = collect($matrix['rows'])->firstWhere('id', 'eav-content-fields');

        $this->assertIsArray($row);
        $this->assertSame('PARTIAL', $row['write_capability_state']);
        $this->assertSame(
            'real_target_partial_children_plus_url_key_rewrite_write_read_restore_collision_verified_2026_09_19',
            $row['real_validation_state'],
        );
        $this->assertStringContainsString('url_key reset explicitly fail_closed', $row['clear_semantics']);
        $this->assertStringContainsString('url_path write remain unsupported', $row['result_or_blocker']);
    }
}
