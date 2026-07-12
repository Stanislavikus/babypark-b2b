<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDefinitionSeederPhase2Test extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $phase2Codes = [
        'weight_netto',
        'weight_brutto',
        'volume_m3',
        'shipping_required',
        'backorder_policy',
        'technical_characteristics',
        'instructions',
    ];

    public function test_seeder_is_idempotent_for_phase_2_fields(): void
    {
        $this->seed(FieldDefinitionSeeder::class);
        $firstCount = FieldDefinition::withoutWorkspaceScope()->count();

        $this->seed(FieldDefinitionSeeder::class);
        $secondCount = FieldDefinition::withoutWorkspaceScope()->count();

        $this->assertSame($firstCount, $secondCount);

        foreach ($this->phase2Codes as $code) {
            $this->assertSame(
                1,
                FieldDefinition::withoutWorkspaceScope()->where('code', $code)->count(),
                "Expected exactly one definition for {$code}"
            );
        }
    }

    public function test_phase_2_field_contracts(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $this->assertFieldContract('weight_netto', [
            'data_type' => AttributeDataType::Decimal,
            'scope' => AttributeScope::System,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.weight_netto',
            'field_group' => 'logistics',
        ]);

        $this->assertFieldContract('backorder_policy', [
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'availability',
        ]);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'technical_characteristics')
            ->firstOrFail();

        $this->assertTrue($definition->is_localizable);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertFieldContract(string $code, array $expected): void
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->where('code', $code)->firstOrFail();
        $this->assertSame($expected['data_type'], $definition->data_type);
        $this->assertSame($expected['scope'], $definition->scope);

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', $expected['object_type'])
            ->firstOrFail();

        $this->assertSame($expected['storage_type'], $binding->storage_type);
        $this->assertSame($expected['storage_path'], $binding->storage_path);
        $this->assertSame($expected['field_group'], $binding->field_group);
    }
}
