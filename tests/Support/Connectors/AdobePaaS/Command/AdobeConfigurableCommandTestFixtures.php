<?php

namespace Tests\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final class AdobeConfigurableCommandTestFixtures
{
    /**
     * @param  list<array{variant_id: string, sku: string, color: string, color_index: string}>  $children
     * @param  list<AdobeProductExportSemanticFinding>  $findings
     */
    public static function configurableSemanticResult(
        int $productId = 1,
        array $children = [],
        array $findings = [],
        bool $blocking = false,
    ): AdobeProductExportSemanticResult {
        if ($children === []) {
            $children = [
                ['variant_id' => '10', 'sku' => 'CHILD-BLUE', 'color' => 'blue', 'color_index' => '93'],
                ['variant_id' => '11', 'sku' => 'CHILD-RED', 'color' => 'red', 'color_index' => '94'],
            ];
        }

        if ($blocking) {
            return new AdobeProductExportSemanticResult($findings, []);
        }

        $operations = [
            new AdobeProductExportSemanticOperation('configurable_parent', [
                'product_id' => $productId,
                'attribute_set_id' => 4,
                'name' => 'Configurable Product',
                'product_type' => 'configurable',
                'visibility' => 'catalog_search',
                'visibility_numeric' => 4,
                'status' => 1,
                'mapped_product_values' => [],
            ]),
            new AdobeProductExportSemanticOperation('configurable_attribute', [
                'external_field_key' => 'color',
                'field_binding_id' => 'binding-color',
                'attribute_id' => 100,
            ]),
            new AdobeProductExportSemanticOperation('option_assignment', [
                'field_binding_id' => 'binding-color',
                'external_field_key' => 'color',
                'attribute_id' => 100,
                'internal_option_key' => 'blue',
                'external_option_value' => '93',
                'value_index' => '93',
            ]),
            new AdobeProductExportSemanticOperation('option_assignment', [
                'field_binding_id' => 'binding-color',
                'external_field_key' => 'color',
                'attribute_id' => 100,
                'internal_option_key' => 'red',
                'external_option_value' => '94',
                'value_index' => '94',
            ]),
        ];

        foreach ($children as $child) {
            $operations[] = new AdobeProductExportSemanticOperation('simple_child', [
                'product_id' => $productId,
                'variant_id' => $child['variant_id'],
                'sku' => $child['sku'],
                'attribute_set_id' => 4,
                'product_type' => 'simple',
                'visibility' => 'not_visible',
                'visibility_numeric' => 1,
                'status' => 1,
                'name' => 'Configurable Product',
                'mapped_product_values' => [],
                'mapped_variant_values' => [],
                'resolved_configurable_values' => [[
                    'field_binding_id' => 'binding-color',
                    'external_field_key' => 'color',
                    'attribute_id' => 100,
                    'internal_option_key' => $child['color'],
                    'value_index' => $child['color_index'],
                ]],
                'resolved_price' => AdobeProductCommandTestFixtures::serializedResolvedPrice(),
            ]);

            $operations[] = new AdobeProductExportSemanticOperation('child_link', [
                'product_id' => $productId,
                'variant_id' => $child['variant_id'],
            ]);
        }

        return new AdobeProductExportSemanticResult($findings, $operations);
    }

    /**
     * @return array<string, mixed>
     */
    public static function remoteParentPayload(string $sku, array $overrides = []): array
    {
        return array_merge([
            'sku' => $sku,
            'name' => 'Configurable Product',
            'attribute_set_id' => 4,
            'type_id' => 'configurable',
            'status' => 1,
            'visibility' => 4,
            'custom_attributes' => [],
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function remoteOptionsPayload(int $attributeId = 100, int $optionId = 201): array
    {
        return [[
            'id' => $optionId,
            'attribute_id' => (string) $attributeId,
            'label' => 'color',
            'position' => 0,
            'values' => [
                ['value_index' => 93],
                ['value_index' => 94],
            ],
        ]];
    }
}
