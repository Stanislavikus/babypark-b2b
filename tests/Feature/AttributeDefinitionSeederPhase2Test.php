<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\AttributeValueLevel;
use App\Models\AttributeDefinition;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeDefinitionSeederPhase2Test extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $phase2Codes = [
        'merchant_type',
        'weight_netto',
        'weight_brutto',
        'volume_m3',
        'shipping_required',
        'backorder_policy',
        'technical_characteristics',
        'instructions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_seeder_creates_phase2_definitions_idempotently(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $firstCount = AttributeDefinition::withoutWorkspaceScope()->count();

        $this->seed(AttributeDefinitionSeeder::class);
        $secondCount = AttributeDefinition::withoutWorkspaceScope()->count();

        $this->assertSame($firstCount, $secondCount);

        foreach ($this->phase2Codes as $code) {
            $this->assertSame(
                1,
                AttributeDefinition::withoutWorkspaceScope()->where('code', $code)->count(),
                "Expected exactly one definition for {$code}"
            );
        }
    }

    public function test_phase2_definitions_have_exact_metadata(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertDefinitionMetadata('merchant_type', [
            'scope' => AttributeScope::System,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::Text,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.merchant_type',
            'attribute_group' => 'basic_information',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => true,
            'status' => AttributeStatus::Active,
            'sort_order' => 120,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => 'Внутрішній тип товару'],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('weight_netto', [
            'scope' => AttributeScope::System,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::Decimal,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.weight_netto',
            'attribute_group' => 'logistics',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => true,
            'status' => AttributeStatus::Active,
            'sort_order' => 130,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => 'Вага нетто'],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('weight_brutto', [
            'scope' => AttributeScope::System,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::Decimal,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.weight_brutto',
            'attribute_group' => 'logistics',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => true,
            'status' => AttributeStatus::Active,
            'sort_order' => 140,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => 'Вага брутто'],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('volume_m3', [
            'scope' => AttributeScope::System,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::Decimal,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.volume_m3',
            'attribute_group' => 'logistics',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => true,
            'status' => AttributeStatus::Active,
            'sort_order' => 150,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => "Об'єм, м³"],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('shipping_required', [
            'scope' => AttributeScope::PlatformLibrary,
            'value_level' => AttributeValueLevel::Variant,
            'data_type' => AttributeDataType::Boolean,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'attribute_group' => 'logistics',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'status' => AttributeStatus::Active,
            'sort_order' => 160,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => 'Потребує доставки'],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('backorder_policy', [
            'scope' => AttributeScope::PlatformLibrary,
            'value_level' => AttributeValueLevel::Variant,
            'data_type' => AttributeDataType::Select,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'attribute_group' => 'availability',
            'is_localizable' => false,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'status' => AttributeStatus::Active,
            'sort_order' => 170,
            'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
            'localized_labels' => ['uk' => 'Замовлення за відсутності'],
            'validation_rules' => [
                'options' => [
                    ['code' => 'deny', 'labels' => ['uk' => 'Не дозволяти']],
                    ['code' => 'continue', 'labels' => ['uk' => 'Дозволяти']],
                ],
            ],
        ]);

        $this->assertDefinitionMetadata('technical_characteristics', [
            'scope' => AttributeScope::PlatformLibrary,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::LongText,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'attribute_group' => 'descriptions',
            'is_localizable' => true,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'status' => AttributeStatus::Active,
            'sort_order' => 180,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'localized_labels' => ['uk' => 'Технічні характеристики'],
            'validation_rules' => null,
        ]);

        $this->assertDefinitionMetadata('instructions', [
            'scope' => AttributeScope::PlatformLibrary,
            'value_level' => AttributeValueLevel::Product,
            'data_type' => AttributeDataType::LongText,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'attribute_group' => 'descriptions',
            'is_localizable' => true,
            'is_multi_value' => false,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'status' => AttributeStatus::Active,
            'sort_order' => 190,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'localized_labels' => ['uk' => 'Інструкція'],
            'validation_rules' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertDefinitionMetadata(string $code, array $expected): void
    {
        $definition = AttributeDefinition::withoutWorkspaceScope()
            ->where('code', $code)
            ->whereNull('workspace_id')
            ->first();

        $this->assertNotNull($definition, "Missing definition for {$code}");

        foreach ($expected as $field => $value) {
            $actual = $definition->{$field};

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if ($actual instanceof \BackedEnum) {
                $actual = $actual->value;
            }

            $this->assertSame($value, $actual, "Mismatch on {$code}.{$field}");
        }
    }
}
