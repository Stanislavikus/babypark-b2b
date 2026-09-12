<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MagentoV1FieldProgressLedgerTest extends TestCase
{
    #[Test]
    public function current_real_target_progress_is_complete_and_resumable(): void
    {
        $rows = $this->rows();

        self::assertCount(102, $rows);
        self::assertCount(102, array_unique(array_column($rows, 'external_field_key')));

        foreach ($rows as $row) {
            foreach (['behavior_class', 'progress_bucket', 'mapping_status', 'certification_status', 'onboarding_readiness', 'evidence_ref', 'next_action'] as $key) {
                self::assertNotSame('', trim($row[$key]), $row['external_field_key'].'.'.$key);
            }
        }

        $bucketCounts = array_count_values(array_column($rows, 'progress_bucket'));
        self::assertSame(12, $bucketCounts['canonical_platform'] ?? 0);
        self::assertSame(45, $bucketCounts['magento_standard'] ?? 0);
        self::assertSame(45, $bucketCounts['workspace_custom'] ?? 0);

        $fullPass = array_filter(
            $rows,
            static fn (array $row): bool => $row['certification_status'] === 'WRITE_READ_RESTORE_PASS',
        );
        self::assertCount(38, $fullPass);
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
    public function verified_adobe_automatic_mappings_are_explicit_and_separate_from_transport_proof(): void
    {
        $verified = array_values(array_map(
            static fn (array $row): string => $row['external_field_key'],
            array_filter(
                $this->rows(),
                static fn (array $row): bool => $row['progress_bucket'] === 'canonical_platform'
                    && $row['mapping_status'] === 'verified_auto_mapping',
            ),
        ));
        sort($verified);

        self::assertSame([
            'category_ids',
            'description',
            'name',
            'short_description',
            'sku',
            'status',
        ], $verified);
    }

    #[Test]
    public function connector_sessions_must_resume_from_the_ledger_instead_of_chat_reconstruction(): void
    {
        $protocol = file_get_contents($this->root('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));
        $agreement = file_get_contents($this->root('docs/05-AI_WORKING_AGREEMENT.md'));
        $map = file_get_contents($this->root('docs/Project_Documentation_Map.md'));
        $matrix = file_get_contents($this->root('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        self::assertStringContainsString('Mandatory per-field progress ledger', $protocol);
        self::assertStringContainsString('Connector certification is not customer onboarding', $protocol);
        self::assertStringContainsString('exact per-code WRITE certification is not an onboarding gate', $protocol);
        self::assertStringContainsString('Continue from the first unresolved `next_action`', $protocol);
        self::assertStringContainsString('restart connector inventory/research from zero', $agreement);
        self::assertStringContainsString('merchant custom attributes into one-by-one vendor research', $agreement);
        self::assertStringContainsString('MAGENTO_V1_FIELD_PROGRESS.md', $map);
        self::assertStringContainsString('magento_v1_custom_behavior_coverage_2026_09_12.json', $map);
        self::assertStringContainsString('Per-field real-target progress is tracked separately', $matrix);
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
