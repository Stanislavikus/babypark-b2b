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
        'NOT_APPLICABLE',
        'MODULE_DEPENDENT',
        'EDITION_DEPENDENT',
        'OPERATIONAL_ONLY',
        'TARGET_DEPENDENT',
    ];

    private const EXPECTED_SURFACE_FAMILIES = [
        'rest_product',
        'stable_system_eav',
        'bulk_import_export',
        'relations',
        'media',
        'pricing',
        'website_store_scope',
        'inventory',
        'configurable',
        'other_product_types',
        'dynamic_eav',
    ];

    #[Test]
    public function authoritative_matrix_rows_are_protocol_aligned_and_individually_traceable(): void
    {
        $matrix = $this->matrix();
        $manifest = $this->manifest();
        $manifestIds = array_fill_keys(array_column($manifest['items'], 'id'), true);

        self::assertTrue($matrix['authoritative']);
        self::assertSame('partial_pending_real_target', $matrix['completion_state']);
        self::assertFalse($matrix['cluster_summaries_are_field_complete']);
        self::assertSame(self::STATUSES, $matrix['status_vocabulary']);
        self::assertNotEmpty($matrix['rows']);

        $rowIds = [];

        foreach ($matrix['rows'] as $row) {
            self::assertSame([], array_diff($matrix['required_row_keys'], array_keys($row)), $row['id']);
            self::assertNotContains($row['id'], $rowIds, 'Duplicate matrix id: '.$row['id']);
            $rowIds[] = $row['id'];

            self::assertNotEmpty($row['source_inventory_ids'], $row['id']);
            self::assertNotSame('', trim($row['external_entity_object']), $row['id']);
            self::assertNotSame('', trim($row['external_field_key_path']), $row['id']);
            self::assertNotSame('', trim($row['type_and_shape']), $row['id']);
            self::assertNotSame('', trim($row['required_semantics']), $row['id']);
            self::assertNotSame('', trim($row['null_semantics']), $row['id']);
            self::assertNotSame('', trim($row['clear_semantics']), $row['id']);
            self::assertNotSame('', trim($row['external_read_contract']), $row['id']);
            self::assertNotSame('', trim($row['external_write_contract']), $row['id']);
            self::assertNotSame('', trim($row['external_restrictions_or_system_owner']), $row['id']);
            self::assertNotSame('', trim($row['version_edition_scope']), $row['id']);
            self::assertNotSame('', trim($row['cluster']), $row['id']);
            self::assertNotSame('', trim($row['platform_domain_owner']), $row['id']);
            self::assertNotSame('', trim($row['platform_representation']), $row['id']);
            self::assertNotSame('', trim($row['connector_read_seam']), $row['id']);
            self::assertNotSame('', trim($row['connector_write_seam']), $row['id']);
            self::assertNotSame('', trim($row['real_validation_state']), $row['id']);
            self::assertNotSame('', trim($row['field_certification_status']), $row['id']);

            foreach ($row['source_inventory_ids'] as $inventoryId) {
                self::assertArrayHasKey($inventoryId, $manifestIds, $row['id']);
            }

            foreach ([
                'read_capability_state',
                'write_capability_state',
                'safe_sync_read_state',
                'safe_sync_write_state',
            ] as $statusKey) {
                self::assertContains($row[$statusKey], self::STATUSES, $row['id'].'.'.$statusKey);
            }
        }
    }

    #[Test]
    public function source_manifest_is_valid_and_covers_every_expected_surface_family(): void
    {
        $manifest = $this->manifest();

        self::assertNotEmpty($manifest['sources']);
        self::assertNotEmpty($manifest['items']);

        $sourceIds = [];
        foreach ($manifest['sources'] as $source) {
            self::assertNotContains($source['id'], $sourceIds, 'Duplicate source id: '.$source['id']);
            $sourceIds[] = $source['id'];
            self::assertNotSame('', trim($source['official_source_url']), $source['id']);
            self::assertNotSame('', trim($source['source_version_marker']), $source['id']);
        }

        $itemIds = [];
        $families = [];
        foreach ($manifest['items'] as $item) {
            self::assertNotContains($item['id'], $itemIds, 'Duplicate manifest item id: '.$item['id']);
            $itemIds[] = $item['id'];

            foreach ([
                'id',
                'source_surface_family',
                'source_surface',
                'exact_external_key_path_or_capability',
                'source_id',
                'official_source_url',
                'source_version_marker',
                'type_and_shape',
                'relevant_scope',
                'field_or_capability_classification',
                'inventory_condition',
                'matrix_row_id',
            ] as $requiredKey) {
                self::assertArrayHasKey($requiredKey, $item, $item['id']);
                self::assertNotSame('', trim((string) $item[$requiredKey]), $item['id'].'.'.$requiredKey);
            }

            self::assertContains($item['source_id'], $sourceIds, $item['id']);
            $families[$item['source_surface_family']] = true;
        }

        self::assertSame(self::EXPECTED_SURFACE_FAMILIES, array_values(array_unique($this->matrix()['expected_source_surface_families'])));

        foreach (self::EXPECTED_SURFACE_FAMILIES as $family) {
            self::assertArrayHasKey($family, $families, 'Missing source surface family: '.$family);
        }
    }

    #[Test]
    public function every_source_manifest_item_maps_to_exactly_one_matrix_outcome(): void
    {
        $manifest = $this->manifest();
        $matrix = $this->matrix();
        $rowsById = [];

        foreach ($matrix['rows'] as $row) {
            $rowsById[$row['id']] = $row;
        }

        foreach ($manifest['items'] as $item) {
            self::assertArrayHasKey($item['matrix_row_id'], $rowsById, $item['id']);
            self::assertContains($item['id'], $rowsById[$item['matrix_row_id']]['source_inventory_ids'], $item['id']);
        }
    }

    #[Test]
    public function every_official_matrix_row_has_source_provenance(): void
    {
        $manifest = $this->manifest();
        $manifestIds = array_fill_keys(array_column($manifest['items'], 'id'), true);

        foreach ($this->matrix()['rows'] as $row) {
            self::assertNotEmpty($row['source_inventory_ids'], $row['id']);

            foreach ($row['source_inventory_ids'] as $inventoryId) {
                self::assertArrayHasKey($inventoryId, $manifestIds, $row['id']);
            }
        }
    }

    #[Test]
    public function exact_rest_eav_and_bulk_alias_bindings_do_not_collapse(): void
    {
        $manifest = $this->manifest()['items'];
        $itemsById = [];
        foreach ($manifest as $item) {
            $itemsById[$item['id']] = $item;
        }

        self::assertNotSame(
            $itemsById['rest-product-type-id']['exact_external_key_path_or_capability'],
            $itemsById['bulk-product-type']['exact_external_key_path_or_capability'],
        );
        self::assertNotSame(
            $itemsById['rest-product-status']['exact_external_key_path_or_capability'],
            $itemsById['bulk-product-online']['exact_external_key_path_or_capability'],
        );
        self::assertNotSame(
            $itemsById['eav-meta-keyword']['exact_external_key_path_or_capability'],
            $itemsById['bulk-meta-keywords']['exact_external_key_path_or_capability'],
        );
        self::assertNotSame(
            $itemsById['eav-tax-class-id']['exact_external_key_path_or_capability'],
            $itemsById['bulk-tax-class-name']['exact_external_key_path_or_capability'],
        );
        self::assertNotSame(
            $itemsById['rest-product-attribute-set-id']['exact_external_key_path_or_capability'],
            $itemsById['bulk-attribute-set-code']['exact_external_key_path_or_capability'],
        );

        $matrixRows = array_fill_keys(array_column($this->matrix()['rows'], 'id'), true);
        foreach ([
            'rest-product-type-id',
            'bulk-product-type',
            'rest-product-status',
            'bulk-product-online',
            'rest-meta-keyword',
            'bulk-meta-keywords',
            'rest-tax-class-id',
            'bulk-tax-class-name',
            'rest-product-attribute-set-id',
            'bulk-attribute-set-code',
        ] as $rowId) {
            self::assertArrayHasKey($rowId, $matrixRows);
        }
    }

    #[Test]
    public function target_dependent_eav_family_remains_explicitly_incomplete_pending_real_target_expansion(): void
    {
        $manifest = $this->manifest()['items'];
        $targetFamily = current(array_filter(
            $manifest,
            static fn (array $item): bool => $item['id'] === 'target-dependent-eav-family',
        ));

        self::assertIsArray($targetFamily);
        self::assertSame('target_dependent', $targetFamily['inventory_condition']);

        $matrixRow = current(array_filter(
            $this->matrix()['rows'],
            static fn (array $row): bool => $row['id'] === 'dynamic-eav-family',
        ));

        self::assertIsArray($matrixRow);
        self::assertSame('pending_real_target_expansion', $matrixRow['field_certification_status']);
        self::assertSame('TARGET_DEPENDENT', $matrixRow['read_capability_state']);
        self::assertSame('TARGET_DEPENDENT', $matrixRow['write_capability_state']);
    }

    #[Test]
    public function documentation_map_points_to_the_matrix_contract(): void
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

        $supportedSafeSyncWriteRows = array_values(array_map(
            static fn (array $row): string => $row['id'],
            array_filter(
                $this->matrix()['rows'],
                static fn (array $row): bool => $row['safe_sync_write_state'] === 'SUPPORTED',
            ),
        ));

        self::assertSame(
            ['rest-product-name', 'rest-product-price', 'rest-product-status', 'rest-product-visibility'],
            $supportedSafeSyncWriteRows,
        );

        $markdown = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));
        foreach ($actualFields as $field) {
            self::assertStringContainsString('`'.$field.'`', $markdown);
        }
        self::assertStringContainsString('Adobe Products / Export / Live = false', $markdown);
        self::assertStringContainsString('trusted simple Product execution consumes', $markdown);
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
        self::assertStringContainsString('Current discovery inputs currently normalized in repository code', $markdown);
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

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->repoPath('docs/connectors/adobe-commerce/magento_v1_product_external_inventory.json'));
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
