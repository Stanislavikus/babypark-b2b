<?php

namespace Tests\Unit\Connectors\Fixtures;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Connectors\Fixtures\MagentoPilotAttributesDiscoveryFixture;
use Tests\TestCase;

class MagentoPilotAttributesDiscoveryFixtureIntegrityTest extends TestCase
{
    #[Test]
    public function verified_real_fixture_preserves_canonical_structure_and_distributions(): void
    {
        $path = __DIR__.'/../../../Support/Connectors/Fixtures/magento_pilot_attributes_discovery_real.json';

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['total_count', 'items'], array_keys($payload));
        $this->assertArrayNotHasKey('captured_at', $payload);
        $this->assertArrayNotHasKey('workspace_id', $payload);
        $this->assertArrayNotHasKey('connector_account_id', $payload);
        $this->assertArrayNotHasKey('pages', $payload);

        $this->assertSame(106, $payload['total_count']);
        $this->assertCount(106, $payload['items']);

        $frontendInput = [];
        $scope = [];
        $backendType = [];
        $visible = 0;
        $invisible = 0;
        $userDefined = 0;
        $system = 0;
        $optionRows = 0;
        $frontendLabelRows = 0;
        $attributeCodes = [];

        foreach ($payload['items'] as $item) {
            $attributeCodes[] = $item['attribute_code'];

            $frontendInputKey = $item['frontend_input'] ?? '__null__';
            $frontendInput[$frontendInputKey] = ($frontendInput[$frontendInputKey] ?? 0) + 1;
            $scope[$item['scope']] = ($scope[$item['scope']] ?? 0) + 1;
            $backendType[$item['backend_type']] = ($backendType[$item['backend_type']] ?? 0) + 1;

            if ($item['is_visible'] ?? false) {
                $visible++;
            } else {
                $invisible++;
            }

            if ($item['is_user_defined'] ?? false) {
                $userDefined++;
            } else {
                $system++;
            }

            if (! empty($item['options'])) {
                $optionRows += count($item['options']);
            }

            if (! empty($item['frontend_labels'])) {
                $frontendLabelRows += count($item['frontend_labels']);
            }
        }

        ksort($frontendInput);
        ksort($scope);
        ksort($backendType);

        $this->assertSame([
            '__null__' => 4,
            'boolean' => 3,
            'date' => 8,
            'gallery' => 2,
            'media_image' => 4,
            'price' => 17,
            'select' => 49,
            'text' => 13,
            'textarea' => 5,
            'weight' => 1,
        ], $frontendInput);

        $this->assertSame([
            'global' => 54,
            'store' => 26,
            'website' => 26,
        ], $scope);

        $this->assertSame([
            'datetime' => 6,
            'decimal' => 19,
            'int' => 47,
            'static' => 7,
            'text' => 4,
            'varchar' => 23,
        ], $backendType);

        $this->assertSame(91, $visible);
        $this->assertSame(15, $invisible);
        $this->assertSame(48, $userDefined);
        $this->assertSame(58, $system);
        $this->assertSame(1426, $optionRows);
        $this->assertSame(368, $frontendLabelRows);

        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES, array_values(array_intersect(
            MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES,
            $attributeCodes,
        )));

        $itemsByCode = collect($payload['items'])->keyBy('attribute_code');

        $this->assertSame([
            'scope' => 'global',
            'backend_type' => 'int',
            'is_required' => true,
        ], [
            'scope' => $itemsByCode['links_purchased_separately']['scope'],
            'backend_type' => $itemsByCode['links_purchased_separately']['backend_type'],
            'is_required' => $itemsByCode['links_purchased_separately']['is_required'],
        ]);

        $this->assertSame([
            'scope' => 'store',
            'backend_type' => 'varchar',
            'is_required' => true,
        ], [
            'scope' => $itemsByCode['samples_title']['scope'],
            'backend_type' => $itemsByCode['samples_title']['backend_type'],
            'is_required' => $itemsByCode['samples_title']['is_required'],
        ]);

        $this->assertSame([
            'scope' => 'store',
            'backend_type' => 'varchar',
            'is_required' => true,
        ], [
            'scope' => $itemsByCode['links_title']['scope'],
            'backend_type' => $itemsByCode['links_title']['backend_type'],
            'is_required' => $itemsByCode['links_title']['is_required'],
        ]);

        $this->assertSame([
            'scope' => 'global',
            'backend_type' => 'int',
            'is_required' => false,
        ], [
            'scope' => $itemsByCode['links_exist']['scope'],
            'backend_type' => $itemsByCode['links_exist']['backend_type'],
            'is_required' => $itemsByCode['links_exist']['is_required'],
        ]);

        foreach (MagentoPilotAttributesDiscoveryFixture::REPRESENTATIVE_INVISIBLE_NORMALIZED_CODES as $code) {
            $this->assertArrayHasKey($code, $itemsByCode->all());
        }

        foreach ($attributeCodes as $code) {
            $this->assertStringNotContainsString('fixture_attr_', $code);
        }
    }

    #[Test]
    public function fixture_helper_returns_fresh_decoded_objects(): void
    {
        $first = MagentoPilotAttributesDiscoveryFixture::allItems();
        $second = MagentoPilotAttributesDiscoveryFixture::allItems();

        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame($first[0]->attribute_code, $second[0]->attribute_code);

        $first[0]->attribute_code = 'mutated';

        $this->assertNotSame('mutated', MagentoPilotAttributesDiscoveryFixture::allItems()[0]->attribute_code);
    }
}
