<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\Category;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Support\Migrations\FieldFoundationCustomerSeed;
use App\Support\Workspace\WorkspaceContext;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CoreFieldNamingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_name_definition_has_customer_and_product_bindings(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $this->assertSame(
            1,
            FieldDefinition::withoutWorkspaceScope()->where('code', 'name')->count()
        );
        $this->assertSame(
            0,
            FieldDefinition::withoutWorkspaceScope()->where('code', 'product_name')->count()
        );

        $nameDefinition = FieldDefinition::withoutWorkspaceScope()->where('code', 'name')->firstOrFail();

        $customerBinding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $nameDefinition->id)
            ->where('object_type', FieldObjectType::Customer)
            ->first();

        $productBinding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $nameDefinition->id)
            ->where('object_type', FieldObjectType::Product)
            ->first();

        $this->assertNotNull($customerBinding);
        $this->assertNotNull($productBinding);
        $this->assertSame('customers.name', $customerBinding->storage_path);
        $this->assertSame('products.name', $productBinding->storage_path);
    }

    public function test_seeder_is_idempotent_for_shared_name_definition(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $nameDefinition = FieldDefinition::withoutWorkspaceScope()->where('code', 'name')->firstOrFail();
        $productBinding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $nameDefinition->id)
            ->where('object_type', FieldObjectType::Product)
            ->firstOrFail();
        $customerBinding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $nameDefinition->id)
            ->where('object_type', FieldObjectType::Customer)
            ->firstOrFail();

        $productBinding->update([
            'is_required' => true,
            'is_filterable' => false,
            'visibility_settings' => ['admin' => false, 'b2b' => true, 'channels' => []],
        ]);

        $productBindingId = $productBinding->id;
        $customerBindingId = $customerBinding->id;
        $nameDefinitionId = $nameDefinition->id;

        $this->seed(FieldDefinitionSeeder::class);

        $productBinding->refresh();
        $this->assertTrue($productBinding->is_required);
        $this->assertFalse($productBinding->is_filterable);
        $this->assertSame($productBindingId, $productBinding->id);
        $this->assertSame($customerBindingId, $customerBinding->fresh()->id);
        $this->assertSame($nameDefinitionId, FieldDefinition::withoutWorkspaceScope()->where('code', 'name')->value('id'));
        $this->assertSame(1, FieldDefinition::withoutWorkspaceScope()->where('code', 'name')->count());
    }

    public function test_migration_preserves_product_column_values(): void
    {
        $renameMigration = require database_path('migrations/2026_07_16_140000_rename_core_product_field_columns.php');
        $renameMigration->down();

        $this->seed(WorkspaceSeeder::class);
        $category = Category::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Test Category',
        ]);

        $productId = DB::table('products')->insertGetId([
            'workspace_id' => app(WorkspaceContext::class)->id(),
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'category_id' => $category->id,
            'product_url' => 'https://babypark.ua/catalog/test-product',
            'weight_netto' => 1.234,
            'weight_brutto' => 2.567,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLegacyFieldMetadata();

        $urlDefId = (string) DB::table('field_definitions')->where('code', 'product_url')->value('id');
        $netDefId = (string) DB::table('field_definitions')->where('code', 'weight_netto')->value('id');
        $grossDefId = (string) DB::table('field_definitions')->where('code', 'weight_brutto')->value('id');
        $productNameBindingId = (string) DB::table('field_bindings')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_bindings.field_definition_id')
            ->where('field_definitions.code', 'product_name')
            ->where('field_bindings.object_type', FieldObjectType::Product->value)
            ->value('field_bindings.id');

        $renameMigration->up();

        $product = Product::query()->findOrFail($productId);
        $product->refresh();
        $this->assertSame('https://babypark.ua/catalog/test-product', $product->url);
        $this->assertSame('1.234', (string) $product->net_weight);
        $this->assertSame('2.567', (string) $product->gross_weight);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('products', 'product_url'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('products', 'weight_netto'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('products', 'weight_brutto'));

        $nameDefinitionId = DB::table('field_definitions')->where('code', 'name')->value('id');
        $this->assertNotNull($nameDefinitionId);
        $this->assertNull(DB::table('field_definitions')->where('code', 'product_name')->value('id'));
        $this->assertSame($productNameBindingId, DB::table('field_bindings')
            ->where('object_type', FieldObjectType::Product->value)
            ->where('field_definition_id', $nameDefinitionId)
            ->value('id'));

        $this->assertSame('url', DB::table('field_definitions')->where('id', $urlDefId)->value('code'));
        $this->assertSame('net_weight', DB::table('field_definitions')->where('id', $netDefId)->value('code'));
        $this->assertSame('gross_weight', DB::table('field_definitions')->where('id', $grossDefId)->value('code'));
        $this->assertSame('products.url', DB::table('field_bindings')->where('storage_path', 'products.url')->value('storage_path'));
    }

    public function test_migration_fails_when_name_product_binding_already_exists(): void
    {
        $renameMigration = require database_path('migrations/2026_07_16_140000_rename_core_product_field_columns.php');
        $renameMigration->down();

        $this->seedLegacyFieldMetadata();

        $nameDefId = DB::table('field_definitions')->where('code', 'name')->value('id');
        DB::table('field_bindings')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'field_definition_id' => $nameDefId,
            'object_type' => FieldObjectType::Product->value,
            'storage_type' => AttributeStorageType::Column->value,
            'storage_path' => 'products.name',
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => true,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => true, 'channels' => []]),
            'sort_order' => 99,
            'status' => AttributeStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target name already has a Product binding');

        $renameMigration->up();
    }

    public function test_migration_fails_on_incompatible_name_definitions(): void
    {
        $renameMigration = require database_path('migrations/2026_07_16_140000_rename_core_product_field_columns.php');
        $renameMigration->down();

        $this->seedLegacyFieldMetadata();

        DB::table('field_definitions')
            ->where('code', 'name')
            ->update(['data_type' => AttributeDataType::Number->value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incompatible FieldDefinition merge');

        $renameMigration->up();
    }

    public function test_migration_down_restores_legacy_columns_and_splits_name_binding(): void
    {
        $renameMigration = require database_path('migrations/2026_07_16_140000_rename_core_product_field_columns.php');
        $renameMigration->down();

        $this->seed(WorkspaceSeeder::class);
        $category = Category::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Rollback Category',
        ]);

        DB::table('products')->insert([
            'workspace_id' => app(WorkspaceContext::class)->id(),
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ROLLBACK-001',
            'name' => 'Rollback Product',
            'category_id' => $category->id,
            'product_url' => 'https://babypark.ua/p/rollback',
            'weight_netto' => 3.456,
            'weight_brutto' => 4.789,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLegacyFieldMetadata();
        $customerNameBindingId = DB::table('field_bindings')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_bindings.field_definition_id')
            ->where('field_definitions.code', 'name')
            ->where('field_bindings.object_type', FieldObjectType::Customer->value)
            ->value('field_bindings.id');

        $renameMigration->up();
        $renameMigration->down();

        $row = DB::table('products')->where('sku', 'ROLLBACK-001')->first();
        $this->assertSame('https://babypark.ua/p/rollback', $row->product_url);
        $this->assertEqualsWithDelta(3.456, (float) $row->weight_netto, 0.001);
        $this->assertEqualsWithDelta(4.789, (float) $row->weight_brutto, 0.001);

        $this->assertNotNull(DB::table('field_definitions')->where('code', 'product_name')->value('id'));
        $this->assertNotNull(DB::table('field_definitions')->where('code', 'name')->value('id'));
        $this->assertSame($customerNameBindingId, DB::table('field_bindings')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_bindings.field_definition_id')
            ->where('field_definitions.code', 'name')
            ->where('field_bindings.object_type', FieldObjectType::Customer->value)
            ->value('field_bindings.id'));
    }

    public function test_active_codebase_has_no_legacy_identifiers_outside_allowlist(): void
    {
        $allowlistPatterns = [
            'database/migrations/2024_06_01_100001_create_products_table.php',
            'database/migrations/2026_06_05_182405_add_product_url_to_products_table.php',
            'database/migrations/2026_07_16_140000_rename_core_product_field_columns.php',
            'app/Support/Migrations/CoreFieldNamingMigrator.php',
            'docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
            'docs/IMPLEMENTATION_GAPS.md',
            'docs/data/canonical_product_field_aliases.csv',
            'docs/data/canonical_product_field_sources.csv',
            'tests/Feature/CoreFieldNamingMigrationTest.php',
        ];

        $legacyPattern = '/\b(product_name|product_url|weight_netto|weight_brutto)\b/';
        $violations = [];

        foreach (File::allFiles(base_path()) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (str_contains($path, 'vendor/') || str_contains($path, 'node_modules/')) {
                continue;
            }

            if (collect($allowlistPatterns)->contains(fn (string $allowed) => str_ends_with($path, $allowed))) {
                continue;
            }

            $contents = File::get($file->getPathname());

            if (preg_match_all($legacyPattern, $contents, $matches)) {
                $violations[$path] = array_values(array_unique($matches[0]));
            }
        }

        $this->assertSame([], $violations, 'Legacy identifiers found outside allowlist: '.json_encode($violations, JSON_PRETTY_PRINT));
    }

    private function seedLegacyFieldMetadata(): void
    {
        $nameDef = DB::table('field_definitions')->where('code', 'name')->first();
        $this->assertNotNull($nameDef);

        $productNameDefId = (string) Str::uuid();
        DB::table('field_definitions')->insert([
            'id' => $productNameDefId,
            'workspace_id' => null,
            'code' => 'product_name',
            'data_type' => AttributeDataType::Text->value,
            'scope' => AttributeScope::System->value,
            'localized_labels' => json_encode(['uk' => 'Назва товару']),
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_bindings')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'field_definition_id' => $productNameDefId,
            'object_type' => FieldObjectType::Product->value,
            'storage_type' => AttributeStorageType::Column->value,
            'storage_path' => 'products.name',
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => true,
            'visibility_settings' => json_encode(['admin' => true, 'b2b' => true, 'channels' => []]),
            'sort_order' => 20,
            'status' => AttributeStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'product_url' => ['products.product_url', AttributeDataType::Url],
            'weight_netto' => ['products.weight_netto', AttributeDataType::Decimal],
            'weight_brutto' => ['products.weight_brutto', AttributeDataType::Decimal],
        ] as $code => [$storagePath, $dataType]) {
            $defId = (string) Str::uuid();
            DB::table('field_definitions')->insert([
                'id' => $defId,
                'workspace_id' => null,
                'code' => $code,
                'data_type' => $dataType->value,
                'scope' => AttributeScope::System->value,
                'localized_labels' => json_encode(['uk' => $code]),
                'description' => null,
                'validation_rules' => null,
                'is_localizable' => false,
                'is_multi_value' => false,
                'status' => AttributeStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('field_bindings')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => null,
                'field_definition_id' => $defId,
                'object_type' => FieldObjectType::Product->value,
                'storage_type' => AttributeStorageType::Column->value,
                'storage_path' => $storagePath,
                'field_group' => 'logistics',
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => true,
                'visibility_settings' => json_encode(['admin' => true, 'b2b' => false, 'channels' => []]),
                'sort_order' => 100,
                'status' => AttributeStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            FieldFoundationCustomerSeed::definitions()['name']['data_type']->value,
            $nameDef->data_type
        );
    }
}
