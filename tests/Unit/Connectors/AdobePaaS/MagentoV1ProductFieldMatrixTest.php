<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MagentoV1ProductFieldMatrixTest extends TestCase
{
    private const STATUSES = [
        'SUPPORTED',
        'PARTIAL',
        'UNSUPPORTED',
        'INSTALLATION_DEPENDENT',
        'NOT_APPLICABLE',
    ];

    #[Test]
    public function authoritative_matrix_rows_are_protocol_aligned_and_individually_certifiable(): void
    {
        $matrix = $this->matrix();
        $markdown = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        self::assertTrue($matrix['authoritative']);
        self::assertSame('partial_pending_real_target', $matrix['completion_state']);
        self::assertFalse($matrix['cluster_summaries_are_field_complete']);
        self::assertSame(self::STATUSES, $matrix['status_vocabulary']);
        self::assertNotEmpty($matrix['sources']);
        self::assertNotEmpty($matrix['rows']);

        $ids = [];

        foreach ($matrix['rows'] as $row) {
            self::assertSame([], array_diff($matrix['required_row_keys'], array_keys($row)), $row['id']);
            self::assertNotContains($row['id'], $ids, 'Duplicate matrix id: '.$row['id']);
            $ids[] = $row['id'];

            self::assertNotSame('', trim($row['cluster']), $row['id']);
            self::assertNotSame('', trim($row['platform_domain_owner']), $row['id']);
            self::assertNotSame('', trim($row['connector_read_seam']), $row['id']);
            self::assertNotSame('', trim($row['connector_write_seam']), $row['id']);
            self::assertNotSame('', trim($row['real_validation_state']), $row['id']);
            self::assertNotSame('', trim($row['field_certification_status']), $row['id']);
            self::assertStringContainsString('`'.$row['id'].'`', $markdown, $row['id']);

            foreach ([
                'read_capability_state',
                'write_capability_state',
                'safe_sync_read_state',
                'safe_sync_write_state',
            ] as $statusKey) {
                self::assertContains($row[$statusKey], self::STATUSES, $row['id'].'.'.$statusKey);
            }

            if ($row['inventory_class'] === 'installation_dependent_field_family') {
                self::assertStringContainsString('target', strtolower($row['installation_condition']), $row['id']);
                self::assertSame('pending_real_target_expansion', $row['field_certification_status'], $row['id']);
            }

            if (in_array('PARTIAL', [
                $row['read_capability_state'],
                $row['write_capability_state'],
                $row['safe_sync_read_state'],
                $row['safe_sync_write_state'],
            ], true) || in_array('UNSUPPORTED', [
                $row['read_capability_state'],
                $row['write_capability_state'],
                $row['safe_sync_read_state'],
                $row['safe_sync_write_state'],
            ], true) || in_array('INSTALLATION_DEPENDENT', [
                $row['read_capability_state'],
                $row['write_capability_state'],
                $row['safe_sync_read_state'],
                $row['safe_sync_write_state'],
            ], true)) {
                self::assertNotSame('', trim($row['result_or_blocker']), $row['id']);
            }
        }
    }

    #[Test]
    public function documentation_map_points_to_the_new_contract(): void
    {
        $map = file_get_contents($this->repoPath('docs/Project_Documentation_Map.md'));

        self::assertStringContainsString(
            'docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md',
            $map,
        );
    }

    #[Test]
    public function safe_sync_allowlist_and_matrix_claims_remain_aligned(): void
    {
        $request = file_get_contents($this->repoPath('integrations/magento-safe-sync/Api/Data/ProductWriteRequestInterface.php'));
        preg_match_all("/public const ([A-Z_]+) = '([^']+)';/", $request, $matches, PREG_SET_ORDER);
        $actualFields = array_column($matches, 2);

        self::assertSame(
            ['expected_sku', 'name', 'status', 'visibility', 'price', 'mapped_attributes'],
            $actualFields,
        );

        $matrix = $this->matrix();
        $supportedSafeSyncWriteRows = array_values(array_map(
            static fn (array $row): string => $row['id'],
            array_filter(
                $matrix['rows'],
                static fn (array $row): bool => $row['safe_sync_write_state'] === 'SUPPORTED',
            ),
        ));

        self::assertSame(
            ['product-name', 'product-status', 'product-visibility', 'product-price'],
            $supportedSafeSyncWriteRows,
        );

        $markdown = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));
        foreach ($actualFields as $field) {
            self::assertStringContainsString('`'.$field.'`', $markdown);
        }
        self::assertStringContainsString('Adobe Products / Export / Live = false', $markdown);
    }

    #[Test]
    public function current_discovery_frontend_inputs_are_documented(): void
    {
        $source = file_get_contents($this->repoPath('app/Support/Connectors/AdobePaaS/AdobePaaSAttributeNormalizer.php'));

        preg_match_all("/'([^']+)'\s*=>\s*'[^']+'/", $source, $matches);
        $actual = array_values(array_unique(array_slice($matches[1], 0, 12)));

        $expected = [
            'text',
            'textarea',
            'texteditor',
            'date',
            'datetime',
            'boolean',
            'select',
            'multiselect',
            'price',
            'media_image',
            'gallery',
            'weight',
        ];

        self::assertSame($expected, $actual);

        $markdown = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));
        foreach ($expected as $frontendInput) {
            self::assertStringContainsString('`'.$frontendInput.'`', $markdown);
        }
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        $contents = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/magento_v1_product_field_matrix.json'));
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
