<?php

namespace Tests\Integration\MySql;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTagWorkspaceForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_mismatched_product_tag_insert_is_rejected_by_composite_foreign_key(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->assertSame('mysql', $driver);

        $workspaceA = (string) Str::uuid();
        $workspaceB = (string) Str::uuid();

        DB::table('workspaces')->insert([
            [
                'id' => $workspaceA,
                'name' => 'Workspace A',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $workspaceB,
                'name' => 'Workspace B',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $productId = DB::table('products')->insertGetId([
            'workspace_id' => $workspaceA,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FK-PRODUCT-001',
            'name' => 'FK product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tagId = (string) Str::uuid();

        DB::table('tags')->insert([
            'id' => $tagId,
            'workspace_id' => $workspaceB,
            'name' => 'sale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('product_tag')->insert([
            'workspace_id' => $workspaceA,
            'product_id' => $productId,
            'tag_id' => $tagId,
        ]);
    }
}
