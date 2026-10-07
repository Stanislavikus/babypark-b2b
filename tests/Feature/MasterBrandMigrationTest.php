<?php

namespace Tests\Feature;

use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Workspace;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class MasterBrandMigrationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
        $this->seed(FieldDefinitionSeeder::class);

        $this->migration = require database_path(
            'migrations/2026_10_07_160000_master_brand_entity.php'
        );
        $this->migration->down();
    }

    #[Test]
    public function migration_backfills_exact_labels_cuts_over_binding_and_round_trips(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $productTypeId = (string) DB::table('product_types')
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->value('id');

        $firstId = DB::table('products')->insertGetId([
            'workspace_id' => $workspace->id,
            'product_type_id' => $productTypeId,
            'name' => 'Upper brand product',
            'brand' => 'Joolz',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = DB::table('products')->insertGetId([
            'workspace_id' => $workspace->id,
            'product_type_id' => $productTypeId,
            'name' => 'Lower brand product',
            'brand' => 'joolz',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $thirdId = DB::table('products')->insertGetId([
            'workspace_id' => $workspace->id,
            'product_type_id' => $productTypeId,
            'name' => 'Repeated upper brand product',
            'brand' => 'Joolz',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyBinding = $this->canonicalBrandBinding();
        $this->assertSame(AttributeStorageType::Column, $legacyBinding->storage_type);
        $this->assertSame('products.brand', $legacyBinding->storage_path);

        $this->migration->up();

        $this->assertTrue(Schema::hasTable('brands'));
        $this->assertTrue(Schema::hasColumn('products', 'brand_id'));
        $this->assertFalse(Schema::hasColumn('products', 'brand'));

        $brands = DB::table('brands')
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $this->assertCount(2, $brands);
        $this->assertEqualsCanonicalizing(['Joolz', 'joolz'], $brands->pluck('name')->all());

        $firstBrandId = DB::table('products')->where('id', $firstId)->value('brand_id');
        $secondBrandId = DB::table('products')->where('id', $secondId)->value('brand_id');
        $thirdBrandId = DB::table('products')->where('id', $thirdId)->value('brand_id');

        $this->assertNotNull($firstBrandId);
        $this->assertNotNull($secondBrandId);
        $this->assertSame($firstBrandId, $thirdBrandId);
        $this->assertNotSame($firstBrandId, $secondBrandId);

        $currentBinding = $this->canonicalBrandBinding();
        $this->assertSame(AttributeStorageType::Relation, $currentBinding->storage_type);
        $this->assertSame('products.brand_id', $currentBinding->storage_path);

        $this->migration->down();

        $this->assertFalse(Schema::hasTable('brands'));
        $this->assertFalse(Schema::hasColumn('products', 'brand_id'));
        $this->assertTrue(Schema::hasColumn('products', 'brand'));
        $this->assertSame('Joolz', DB::table('products')->where('id', $firstId)->value('brand'));
        $this->assertSame('joolz', DB::table('products')->where('id', $secondId)->value('brand'));
        $this->assertSame('Joolz', DB::table('products')->where('id', $thirdId)->value('brand'));

        $rolledBackBinding = $this->canonicalBrandBinding();
        $this->assertSame(AttributeStorageType::Column, $rolledBackBinding->storage_type);
        $this->assertSame('products.brand', $rolledBackBinding->storage_path);
    }

    #[Test]
    public function migration_fails_closed_on_whitespace_legacy_label_before_schema_change(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $productTypeId = (string) DB::table('product_types')
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->value('id');

        DB::table('products')->insert([
            'workspace_id' => $workspace->id,
            'product_type_id' => $productTypeId,
            'name' => 'Ambiguous legacy Brand',
            'brand' => ' Joolz',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->migration->up();
            $this->fail('Whitespace legacy Brand must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('leading/trailing whitespace', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('brands'));
        $this->assertFalse(Schema::hasColumn('products', 'brand_id'));
        $this->assertTrue(Schema::hasColumn('products', 'brand'));

        $binding = $this->canonicalBrandBinding();
        $this->assertSame(AttributeStorageType::Column, $binding->storage_type);
        $this->assertSame('products.brand', $binding->storage_path);
    }

    private function canonicalBrandBinding(): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereNull('workspace_id')
            ->where('code', 'brand')
            ->sole();

        return FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product->value)
            ->sole();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }
}
