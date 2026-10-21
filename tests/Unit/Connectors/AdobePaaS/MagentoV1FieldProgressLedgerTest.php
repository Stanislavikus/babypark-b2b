<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MagentoV1FieldProgressLedgerTest extends TestCase
{
    #[Test]
    public function current_real_target_runtime_evidence_is_complete_and_resumable(): void
    {
        $evidence = $this->runtimeEvidence();

        self::assertSame('magento_v1_stage1_real_target_evidence.v1', $evidence['schema_version']);
        self::assertSame('runtime_db_projection', $evidence['source']);
        self::assertSame(106, $evidence['snapshot']['fields_received']);
        self::assertSame(106, $evidence['snapshot']['field_count']);
        self::assertSame('v2', $evidence['snapshot']['canonical_hash_version']);
        self::assertSame(0, $evidence['summary']['silent_drop_count']);
        self::assertSame(['normalized' => 102, 'unclassified' => 4], $evidence['summary']['normalization']);
        self::assertSame([
            'canonical_platform' => 6,
            'provider_standard' => 22,
            'review_needed' => 4,
            'system_or_dedicated_owner' => 27,
            'workspace_custom' => 47,
        ], $evidence['summary']['dispositions']);
        self::assertSame(4, $evidence['summary']['actual_mapping_count']);
        self::assertSame(4, $evidence['summary']['review_needed_count']);
        self::assertCount(106, $evidence['fields']);
        self::assertCount(106, array_unique(array_column($evidence['fields'], 'external_field_key')));
        self::assertSame([
            'custom_layout_update_file',
            'links_exist',
            'old_id',
            'samples_title',
        ], $evidence['review_needed_keys']);
    }

    #[Test]
    public function workspace_custom_rows_are_all_onboarding_ready_without_faking_exact_write_proof(): void
    {
        $custom = array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['progress_bucket'] === 'workspace_custom',
        ));
        $exact = array_values(array_filter(
            $custom,
            static fn (array $row): bool => $row['certification_status'] === 'WRITE_READ_RESTORE_PASS',
        ));
        $covered = array_values(array_filter(
            $custom,
            static fn (array $row): bool => $row['certification_status'] === 'BEHAVIOR_CLASS_COVERED_NO_EXACT_WRITE',
        ));

        self::assertCount(45, $custom);
        self::assertCount(24, $exact);
        self::assertCount(21, $covered);
        self::assertCount(16, array_filter(
            $covered,
            static fn (array $row): bool => $row['behavior_class'] === 'select/global',
        ));
        self::assertCount(5, array_filter(
            $covered,
            static fn (array $row): bool => $row['behavior_class'] === 'money/website',
        ));
        self::assertCount(45, array_filter(
            $custom,
            static fn (array $row): bool => str_starts_with($row['onboarding_readiness'], 'ready_'),
        ));
        self::assertCount(0, array_filter(
            $custom,
            static fn (array $row): bool => $row['certification_status'] === 'NOT_YET_CERTIFIED',
        ));
    }

    #[Test]
    public function custom_behavior_coverage_records_live_catalog_evidence_without_claiming_exact_writes(): void
    {
        $coverage = json_decode(
            file_get_contents($this->root('docs/connectors/adobe-commerce/magento_v1_custom_behavior_coverage_2026_09_12.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(19, $coverage['catalog_total_count']);
        self::assertTrue($coverage['exact_field_write_read_restore_not_claimed_by_this_artifact']);
        self::assertCount(21, $coverage['fields']);
        self::assertCount(18, array_filter(
            $coverage['fields'],
            static fn (array $row): bool => $row['live_value_observed'] === true,
        ));
        $schemaOnly = array_values(array_map(
            static fn (array $row): string => $row['external_field_key'],
            array_filter(
                $coverage['fields'],
                static fn (array $row): bool => $row['live_value_observed'] === false,
            ),
        ));
        sort($schemaOnly);
        self::assertSame([
            'c_carseats_child_gender',
            'c_furniture_color',
            'c_strollers_color_joolz_day_5',
        ], $schemaOnly);
    }

    #[Test]
    public function runtime_evidence_records_only_actual_verified_generic_mappings(): void
    {
        $evidence = $this->runtimeEvidence();
        $actual = array_column($evidence['actual_mappings'], 'external_field_key');
        sort($actual);

        self::assertSame(['description', 'name', 'sku', 'status'], $actual);
        self::assertCount(12, $evidence['canonical_audit']);

        $byKey = [];
        foreach ($evidence['canonical_audit'] as $row) {
            $byKey[$row['external_field_key']] = $row;
        }

        self::assertSame('relations', $byKey['category_ids']['runtime_owner_hint']);
        self::assertSame('workspace_custom', $byKey['color']['disposition']);
        self::assertSame('media', $byKey['image']['runtime_owner_hint']);
        self::assertSame('workspace_custom', $byKey['manufacturer']['disposition']);
        self::assertSame('channel_deferred', $byKey['meta_title']['mapping_strategy']);
        self::assertSame('channel_deferred', $byKey['meta_description']['mapping_strategy']);
        self::assertSame('pricing', $byKey['price']['runtime_owner_hint']);
        self::assertSame('canonical_target_not_ready', $byKey['short_description']['mapping_strategy']);
    }

    #[Test]
    public function connector_sessions_must_resume_from_runtime_truth_instead_of_chat_reconstruction(): void
    {
        $protocol = file_get_contents($this->root('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));
        $agreement = file_get_contents($this->root('docs/05-AI_WORKING_AGREEMENT.md'));
        $map = file_get_contents($this->root('docs/Project_Documentation_Map.md'));
        $matrix = file_get_contents($this->root('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        self::assertStringContainsString('Mandatory per-field runtime progress state', $protocol);
        self::assertStringContainsString('Connector certification is not customer onboarding', $protocol);
        self::assertStringContainsString('exact per-code WRITE certification is not an onboarding gate', $protocol);
        self::assertStringContainsString('persisted runtime classification plus its latest generated evidence export', $protocol);
        self::assertStringContainsString('restart connector inventory/research from zero', $agreement);
        self::assertStringContainsString('merchant custom attributes into one-by-one vendor research', $agreement);
        self::assertStringContainsString('MAGENTO_V1_FIELD_PROGRESS.md', $map);
        self::assertStringContainsString('magento_v1_stage1_real_target_evidence_2026_09_13.json', $map);
        self::assertStringContainsString('magento_v1_custom_behavior_coverage_2026_09_12.json', $map);
        self::assertStringContainsString('Per-field real-target progress is tracked separately', $matrix);
    }

    /** @return array<string, mixed> */
    private function runtimeEvidence(): array
    {
        return json_decode(
            file_get_contents($this->root('docs/connectors/adobe-commerce/magento_v1_stage1_real_target_evidence_2026_09_13.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $handle = fopen(
            $this->root('docs/connectors/adobe-commerce/magento_v1_real_target_field_progress_2026_09_12.csv'),
            'rb',
        );
        self::assertNotFalse($handle);

        $header = fgetcsv($handle);
        self::assertIsArray($header);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $values);
            self::assertIsArray($row);
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function root(string $path): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
