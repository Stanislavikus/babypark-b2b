<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldMappingDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_field_mapping_first_persistence_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '#### FieldMapping first persistence contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Task 4C-1a, 2026-08-12]', $content);
    }

    #[Test]
    public function contract_uses_field_binding_id_and_external_field_key(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('`field_binding_id`', $section);
        $this->assertStringContainsString('`external_field_key`', $section);
        $this->assertStringContainsString('Minimum physical schema — `field_mappings`', $section);
    }

    #[Test]
    public function contract_is_direction_neutral_and_not_snapshot_identity(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('direction-neutral semantic correspondence', $section);
        $this->assertStringContainsString('not an execution plan', $section);
        $this->assertStringContainsString(
            'Do **not** reference `connector_schema_snapshot_fields.id` as persistent mapping',
            $section,
        );
        $this->assertStringContainsString('`snapshot_field_id`', $section);
        $this->assertStringContainsString('Not in the minimum table', $section);
    }

    #[Test]
    public function contract_separates_effective_mappings_from_suggestions(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('Suggestions are not effective FieldMappings', $section);
        $this->assertMatchesRegularExpression(
            '/must\s+\*\*not\*\*\s+auto-persist as effective mappings/',
            $section,
        );
        $this->assertStringContainsString('Prefill = presentation /', $section);
        $this->assertStringContainsString('confirmed `field_mappings` row = effective configuration state', $section);
    }

    #[Test]
    public function contract_requires_mappings_in_configuration_revision_v2(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('babypark.sync-configuration-revision.v2', $section);
        $this->assertStringContainsString('"field_mappings"', $section);
        $this->assertStringContainsString('atomically advance `SyncConfiguration.configuration_revision`', $section);
        $this->assertStringContainsString('no-op', $section);
    }

    #[Test]
    public function contract_limits_first_slice_to_products_field_bindings(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('`data_domain` | `products` only', $section);
        $this->assertStringContainsString('`FieldBinding` only', $section);
        $this->assertStringContainsString('`product`, `product_variant`', $section);
        $this->assertStringContainsString('reject `customer`', $section);
    }

    #[Test]
    public function contract_defers_non_field_binding_domain_targets(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('pricing-domain, availability-domain, media-relation', $section);
        $this->assertStringContainsString(
            'Do not add polymorphic target columns',
            $section,
        );
    }

    #[Test]
    public function contract_documents_one_to_one_cardinality_inside_sync_configuration(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString(
            'UNIQUE(sync_configuration_id, field_binding_id)',
            $section,
        );
        $this->assertStringContainsString(
            'UNIQUE(sync_configuration_id, external_field_key)',
            $section,
        );
    }

    #[Test]
    public function implementation_gaps_records_task_4c_1a_done_and_4c_1b_next(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**4C-1a** | FieldMapping persistence contract', $content);
        $this->assertStringContainsString('**4C-1b**', $content);
        $this->assertStringContainsString('**4C-1c**', $content);
        $this->assertStringContainsString('Task 4C-1a settled the FieldMapping first persistence contract', $content);
    }

    /**
     * @return non-empty-string
     */
    private function fieldMappingPersistenceContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### FieldMapping first persistence contract\n\[Resolved — Task 4C-1a, 2026-08-12\]\n\n(.*?)(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate FieldMapping first persistence contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
