<?php

namespace Tests\Feature;

use App\Enums\ProductLifecycleStatus;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductLifecycleMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
    }

    #[Test]
    public function lifecycle_migration_backfills_preexisting_boolean_state_and_adds_index(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $first = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Pre-migration active',
            'is_active' => true,
        ]);
        $second = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Pre-migration inactive',
            'is_active' => true,
        ]);

        $migration = require database_path(
            'migrations/2026_10_05_140000_add_product_lifecycle_status.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('products', 'lifecycle_status'));

        DB::table('products')->where('id', $first->id)->update(['is_active' => true]);
        DB::table('products')->where('id', $second->id)->update(['is_active' => false]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('products', 'lifecycle_status'));
        $this->assertSame(
            ProductLifecycleStatus::Active->value,
            DB::table('products')->where('id', $first->id)->value('lifecycle_status'),
        );
        $this->assertSame(
            ProductLifecycleStatus::Archived->value,
            DB::table('products')->where('id', $second->id)->value('lifecycle_status'),
        );

        if (DB::getDriverName() === 'mysql') {
            $indexCount = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'products')
                ->where('INDEX_NAME', 'products_lifecycle_status_index')
                ->count();

            $this->assertGreaterThan(0, $indexCount);
        }
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }
}
