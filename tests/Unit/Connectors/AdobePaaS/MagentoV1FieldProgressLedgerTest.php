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
            foreach (['progress_bucket', 'mapping_status', 'certification_status', 'evidence_ref', 'next_action'] as $key) {
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
    public function remaining_workspace_custom_work_is_two_bounded_known_behavior_batches(): void
    {
        $custom = array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['progress_bucket'] === 'workspace_custom',
        ));
        $passed = array_values(array_filter(
            $custom,
            static fn (array $row): bool => $row['certification_status'] === 'WRITE_READ_RESTORE_PASS',
        ));
        $pending = array_values(array_filter(
            $custom,
            static fn (array $row): bool => $row['certification_status'] === 'NOT_YET_CERTIFIED',
        ));

        self::assertCount(45, $custom);
        self::assertCount(24, $passed);
        self::assertCount(21, $pending);
        self::assertCount(16, array_filter(
            $pending,
            static fn (array $row): bool => $row['normalized_data_type'] === 'select' && $row['external_scope'] === 'global',
        ));
        self::assertCount(5, array_filter(
            $pending,
            static fn (array $row): bool => $row['normalized_data_type'] === 'money' && $row['external_scope'] === 'website',
        ));
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
        self::assertStringContainsString('Continue from the first unresolved `next_action`', $protocol);
        self::assertStringContainsString('restart connector inventory/research from zero', $agreement);
        self::assertStringContainsString('MAGENTO_V1_FIELD_PROGRESS.md', $map);
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
