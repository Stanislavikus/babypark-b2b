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
    public function authoritative_matrix_is_complete_consistent_and_present_in_the_human_audit(): void
    {
        $matrix = $this->matrix();
        $markdown = file_get_contents(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        self::assertTrue($matrix['authoritative']);
        self::assertSame(self::STATUSES, $matrix['status_vocabulary']);
        self::assertNotEmpty($matrix['sources']);
        self::assertNotEmpty($matrix['rows']);
        self::assertNotEmpty($matrix['gaps']);

        $identities = [];
        $statusKeys = [
            'discovery_support',
            'field_mapping_support',
            'field_option_mapping_support',
            'preview_support',
            'receive_support',
            'live_command_support',
            'safe_sync_read_exposure',
            'safe_sync_write_exposure',
        ];

        foreach ($matrix['rows'] as $row) {
            self::assertSame([], array_diff($matrix['required_row_keys'], array_keys($row)), $row['id']);
            self::assertNotContains($row['id'], $identities, 'Duplicate matrix id: '.$row['id']);
            $identities[] = $row['id'];
            self::assertStringContainsString('| '.$row['id'].' |', $markdown, $row['id']);

            foreach ($statusKeys as $statusKey) {
                self::assertContains($row[$statusKey], self::STATUSES, $row['id'].'.'.$statusKey);
            }

            $hasConditionalStatus = in_array('INSTALLATION_DEPENDENT', array_intersect_key($row, array_flip($statusKeys)), true);
            $isInstallationDependentContract = str_contains(strtolower($row['attribute_code']), 'installation')
                || str_contains(strtolower($row['version_applicability']), 'installation');

            if ($hasConditionalStatus || $isInstallationDependentContract) {
                self::assertNotSame('', trim($row['installation_condition']), $row['id']);
            }

            if (array_intersect(['PARTIAL', 'UNSUPPORTED'], array_values(array_intersect_key($row, array_flip($statusKeys))))) {
                self::assertNotSame('', trim($row['current_blocker']), $row['id']);
            }
        }
    }

    #[Test]
    public function discovery_frontend_input_claim_matches_the_normalizer_allowlist(): void
    {
        $source = file_get_contents(base_path('app/Support/Connectors/AdobePaaS/AdobePaaSAttributeNormalizer.php'));

        preg_match_all("/'([^']+)'\s*=>\s*'[^']+'/", $source, $matches);

        $actual = array_values(array_unique(array_slice($matches[1], 0, 12)));
        $expected = [
            'text', 'textarea', 'texteditor', 'date', 'datetime', 'boolean',
            'select', 'multiselect', 'price', 'media_image', 'gallery', 'weight',
        ];

        self::assertSame($expected, $actual);

        $audit = file_get_contents(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));
        foreach ($expected as $frontendInput) {
            self::assertStringContainsString('`'.$frontendInput.'`', $audit);
        }
    }

    #[Test]
    public function safe_sync_write_claim_matches_the_request_contract_and_live_stays_gated(): void
    {
        $request = file_get_contents(base_path('integrations/magento-safe-sync/Api/Data/ProductWriteRequestInterface.php'));
        preg_match_all("/public const ([A-Z_]+) = '([^']+)';/", $request, $matches, PREG_SET_ORDER);
        $actual = array_column($matches, 2);

        self::assertSame(
            ['expected_sku', 'name', 'status', 'visibility', 'price', 'mapped_attributes'],
            $actual,
        );

        $adapter = file_get_contents(base_path('app/Support/Connectors/AdobePaaS/AdobePaaSConnectorAdapter.php'));
        self::assertStringContainsString('return $mode === SyncRunMode::Preview;', $adapter);

        $audit = file_get_contents(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));
        foreach ($actual as $field) {
            self::assertStringContainsString('`'.$field.'`', $audit);
        }
        self::assertStringContainsString('Products/Export/Live remains false', $audit);
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        $contents = file_get_contents(base_path('docs/connectors/adobe-commerce/magento_v1_product_field_matrix.json'));
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
