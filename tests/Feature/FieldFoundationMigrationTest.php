<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Support\Migrations\FieldFoundationCustomerSeed;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FieldFoundationMigrationTest extends TestCase
{
    public function test_field_foundation_roundtrip_preserves_product_variant_data(): void
    {
        $this->migrateThroughPreFieldFoundation();

        $bothId = (string) Str::uuid();
        DB::table('attribute_definitions')->insert([
            'id' => $bothId,
            'workspace_id' => null,
            'code' => 'synthetic_both',
            'data_type' => AttributeDataType::Text->value,
            'scope' => AttributeScope::PlatformLibrary->value,
            'value_level' => 'both',
            'storage_type' => 'dynamic',
            'storage_path' => null,
            'attribute_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => true, 'channels' => []]),
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active->value,
            'sort_order' => 999,
            'localized_labels' => json_encode(['uk' => 'Синтетичне both']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $countsBefore = [
            'definitions' => DB::table('attribute_definitions')->count(),
            'product_values' => DB::table('product_attribute_values')->count(),
            'variant_values' => DB::table('variant_attribute_values')->count(),
        ];

        $migration = require database_path('migrations/2026_07_12_150000_field_foundation.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('attribute_definitions'));
        $this->assertTrue(Schema::hasTable('field_definitions'));
        $this->assertSame(15, DB::table('field_bindings')->where('object_type', 'customer')->count());
        $this->assertSame($countsBefore['definitions'] + 15, DB::table('field_definitions')->count());
        $this->assertSame($countsBefore['product_values'], DB::table('product_field_values')->count());
        $this->assertSame($countsBefore['variant_values'], DB::table('variant_field_values')->count());

        $migration->down();

        $this->assertTrue(Schema::hasTable('attribute_definitions'));
        $this->assertFalse(Schema::hasTable('field_definitions'));
        $this->assertSame($countsBefore['definitions'], DB::table('attribute_definitions')->count());
        $this->assertSame($countsBefore['product_values'], DB::table('product_attribute_values')->count());
        $this->assertSame($countsBefore['variant_values'], DB::table('variant_attribute_values')->count());

        $migration->up();

        $this->assertFalse(Schema::hasTable('attribute_definitions'));
        $this->assertSame($countsBefore['definitions'] + 15, DB::table('field_definitions')->count());
    }

    public function test_customer_email_reuse_branch_shares_definition_id(): void
    {
        $this->migrateThroughPreFieldFoundation();

        $emailFixtureId = (string) Str::uuid();
        $emailDef = FieldFoundationCustomerSeed::definitions()['email'];

        DB::table('attribute_definitions')->insert([
            'id' => $emailFixtureId,
            'workspace_id' => null,
            'code' => 'email',
            'data_type' => $emailDef['data_type']->value,
            'scope' => AttributeScope::System->value,
            'value_level' => 'product',
            'storage_type' => 'column',
            'storage_path' => 'products.email',
            'attribute_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => false, 'channels' => []]),
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active->value,
            'sort_order' => 10,
            'localized_labels' => json_encode($emailDef['localized_labels']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_12_150000_field_foundation.php');
        $migration->up();

        $emailDefinitions = DB::table('field_definitions')->where('code', 'email')->get();
        $this->assertCount(1, $emailDefinitions);
        $this->assertSame($emailFixtureId, $emailDefinitions->first()->id);

        $bindings = DB::table('field_bindings')
            ->where('field_definition_id', $emailFixtureId)
            ->get();

        $this->assertTrue($bindings->contains(fn ($b) => $b->object_type === 'product'));
        $this->assertTrue($bindings->contains(fn ($b) => $b->object_type === 'customer'));

        $migration->down();

        $this->assertTrue(
            DB::table('attribute_definitions')->where('id', $emailFixtureId)->where('value_level', 'product')->exists()
        );

        $migration->up();

        $this->assertCount(1, DB::table('field_definitions')->where('code', 'email')->get());
    }

    public function test_incompatible_global_definition_description_fails_before_ddl(): void
    {
        $this->migrateThroughPreFieldFoundation();

        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->text('description')->nullable();
        });

        $emailDef = FieldFoundationCustomerSeed::definitions()['email'];

        DB::table('attribute_definitions')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => 'email',
            'data_type' => $emailDef['data_type']->value,
            'scope' => AttributeScope::System->value,
            'value_level' => 'product',
            'storage_type' => 'column',
            'storage_path' => 'products.email',
            'attribute_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => false, 'channels' => []]),
            'validation_rules' => null,
            'is_localizable' => $emailDef['is_localizable'],
            'is_multi_value' => $emailDef['is_multi_value'],
            'status' => $emailDef['status']->value,
            'sort_order' => 10,
            'localized_labels' => json_encode($emailDef['localized_labels']),
            'description' => 'Fixture description incompatible with seed null',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definitionCountBefore = DB::table('attribute_definitions')->count();

        $migration = require database_path('migrations/2026_07_12_150000_field_foundation.php');

        try {
            $migration->up();
            $this->fail('Expected migration to fail on incompatible email description');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
            $this->assertStringContainsString('description', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('field_definitions'));
        $this->assertTrue(Schema::hasTable('attribute_definitions'));
        $this->assertSame($definitionCountBefore, DB::table('attribute_definitions')->count());

        // Only description mismatch blocked preflight; aligning it lets migration proceed.
        DB::table('attribute_definitions')
            ->whereNull('workspace_id')
            ->where('code', 'email')
            ->update(['description' => null]);

        $migration->up();

        $this->assertTrue(Schema::hasTable('field_definitions'));
    }

    public function test_incompatible_global_definition_fails_before_ddl(): void
    {
        $this->migrateThroughPreFieldFoundation();

        DB::table('attribute_definitions')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => 'email',
            'data_type' => AttributeDataType::Number->value,
            'scope' => AttributeScope::System->value,
            'value_level' => 'product',
            'storage_type' => 'column',
            'storage_path' => 'products.email',
            'attribute_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => false, 'channels' => []]),
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active->value,
            'sort_order' => 10,
            'localized_labels' => json_encode(['uk' => 'Email']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_12_150000_field_foundation.php');

        try {
            $migration->up();
            $this->fail('Expected migration to fail on incompatible email definition');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('field_definitions'));
        $this->assertTrue(Schema::hasTable('attribute_definitions'));
    }

    public function test_workspace_code_uniqueness_is_tenant_isolated(): void
    {
        Artisan::call('migrate:fresh');

        $workspaceA = (string) Str::uuid();
        $workspaceB = (string) Str::uuid();

        DB::table('workspaces')->insert([
            ['id' => $workspaceA, 'name' => 'Workspace A', 'is_default' => false, 'default_vat_rate' => 20, 'default_price_display_mode' => 'tax_inclusive_primary', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $workspaceB, 'name' => 'Workspace B', 'is_default' => false, 'default_vat_rate' => 20, 'default_price_display_mode' => 'tax_inclusive_primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $definitionId = fn () => (string) Str::uuid();
        $bindingId = fn () => (string) Str::uuid();

        foreach ([$workspaceA, $workspaceB] as $workspaceId) {
            $defId = $definitionId();
            DB::table('field_definitions')->insert([
                'id' => $defId,
                'workspace_id' => $workspaceId,
                'code' => 'color',
                'data_type' => 'text',
                'scope' => 'workspace_custom',
                'localized_labels' => json_encode(['uk' => 'Колір']),
                'description' => null,
                'validation_rules' => null,
                'is_localizable' => false,
                'is_multi_value' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('field_bindings')->insert([
                'id' => $bindingId(),
                'workspace_id' => $workspaceId,
                'field_definition_id' => $defId,
                'object_type' => FieldObjectType::Product->value,
                'storage_type' => 'dynamic',
                'storage_path' => null,
                'field_group' => 'characteristics',
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'visibility_settings' => json_encode(['admin' => true, 'b2b' => true, 'channels' => []]),
                'sort_order' => 10,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->expectException(QueryException::class);
        DB::table('field_definitions')->insert([
            'id' => $definitionId(),
            'workspace_id' => $workspaceA,
            'code' => 'color',
            'data_type' => 'text',
            'scope' => 'workspace_custom',
            'localized_labels' => json_encode(['uk' => 'Другий колір']),
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_field_definition_seeder_is_idempotent(): void
    {
        Artisan::call('migrate:fresh');

        $this->seed(FieldDefinitionSeeder::class);
        $firstDefinitions = DB::table('field_definitions')->count();
        $firstBindings = DB::table('field_bindings')
            ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
            ->count();

        $this->seed(FieldDefinitionSeeder::class);

        $this->assertSame($firstDefinitions, DB::table('field_definitions')->count());
        $this->assertSame($firstBindings, DB::table('field_bindings')
            ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
            ->count());
    }

    private function migrateThroughPreFieldFoundation(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true]);

        $migration = require database_path('migrations/2026_07_12_150000_field_foundation.php');
        $migration->down();

        $this->assertTrue(Schema::hasTable('attribute_definitions'));
        $this->assertFalse(Schema::hasTable('field_definitions'));
    }
}
