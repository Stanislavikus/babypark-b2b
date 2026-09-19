<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MagentoV1ReceiveR4RealTargetCertificationDocumentationContractTest extends TestCase
{
    #[Test]
    public function p07_real_target_evidence_records_variant_subject_dynamic_select_receive_and_exact_restore(): void
    {
        $path = base_path('docs/connectors/adobe-commerce/magento_v1_receive_r4_real_target_certification_2026_09_19.json');

        $this->assertFileExists($path);

        $evidence = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('P-07', data_get($evidence, 'pending_item'));
        $this->assertSame('resolved_pass', data_get($evidence, 'status'));
        $this->assertFalse(data_get($evidence, 'public_import_support_changed'));

        $this->assertSame('product_variant', data_get($evidence, 'target.receive_target_type'));
        $this->assertSame(3, data_get($evidence, 'target.local_variant_id'));
        $this->assertSame(3, data_get($evidence, 'target.remote_logical_entity_id'));
        $this->assertSame('c_strollers_hand_luggage', data_get($evidence, 'field.external_field_key'));
        $this->assertSame('1029', data_get($evidence, 'field.baseline_external_option'));
        $this->assertSame('1028', data_get($evidence, 'field.probe_external_option'));

        $this->assertSame('not_applied', data_get($evidence, 'real_target_stale_local_proof.outcome'));
        $this->assertSame(
            'receive_apply_local_value_changed',
            data_get($evidence, 'real_target_stale_local_proof.finding_code'),
        );

        $this->assertSame('synchronized', data_get($evidence, 'receive_apply_probe.outcome'));
        $this->assertSame('receive_dynamic_field_applied', data_get($evidence, 'receive_apply_probe.finding_code'));
        $this->assertSame('synchronized', data_get($evidence, 'receive_apply_restore.outcome'));
        $this->assertSame('1029', data_get($evidence, 'cleanup.remote_option_final'));

        $this->assertTrue(data_get($evidence, 'cleanup.configuration_revision_matches_baseline'));
        $this->assertSame(['export'], data_get($evidence, 'cleanup.enabled_operations_final'));
        $this->assertSame(0, data_get($evidence, 'cleanup.temporary_external_record_links_remaining'));
        $this->assertSame(0, data_get($evidence, 'cleanup.temporary_field_definitions_remaining'));
        $this->assertSame(0, data_get($evidence, 'cleanup.temporary_field_mappings_remaining'));
        $this->assertSame(0, data_get($evidence, 'cleanup.temporary_variant_values_remaining'));

        $this->assertGreaterThan(
            data_get($evidence, 'cleanup.product_structure_cleanup.structure_revision_before_placement_removal'),
            data_get($evidence, 'cleanup.product_structure_cleanup.structure_revision_after_placement_removal'),
        );
        $this->assertSame(
            'monotonic; intentionally not rewound',
            data_get($evidence, 'cleanup.product_structure_cleanup.revision_policy'),
        );

        $this->assertFalse(data_get($evidence, 'verification_boundary.products_import_live_public_support'));
        $this->assertFalse(data_get($evidence, 'verification_boundary.remote_absent_clear'));
        $this->assertFalse(data_get($evidence, 'verification_boundary.other_dynamic_datatypes_admitted'));
        $this->assertFalse(data_get($evidence, 'verification_boundary.merchant_apply_ui_present'));
    }

    #[Test]
    public function current_docs_close_p07_without_flipping_public_import_support(): void
    {
        $ledger = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md'));
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('P-07 — Magento Receive Apply breadth — CLOSED 2026-09-19 [Resolved]', $ledger);
        $this->assertStringContainsString('standalone Magento Simple identity is Variant-subject, not Product-subject', $ledger);
        $this->assertStringContainsString('magento_v1_receive_r4_real_target_certification_2026_09_19.json', $ledger);
        $this->assertStringContainsString('public Adobe Products / Import / Live remains **false**', $ledger);

        $this->assertStringContainsString('Real-target certification record — 2026-09-19', $domain);
        $this->assertStringContainsString('certified identity is Variant-subject, not Product-subject', $domain);
        $this->assertStringContainsString('does not flip public', $domain);

        $this->assertStringContainsString(
            'Receive Apply runtime | IMPLEMENTED (internal; R3 Product `name` + R4 workspace-custom Dynamic single-select; public Adobe Import/Live support false; real-target certified 2026-09-11 and 2026-09-19)',
            $atlas,
        );
        $this->assertStringContainsString('magento_v1_receive_r4_real_target_certification_2026_09_19.json', $map);
    }
}
