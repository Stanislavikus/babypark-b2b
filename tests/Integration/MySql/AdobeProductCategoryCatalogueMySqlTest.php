<?php

namespace Tests\Integration\MySql;

use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class AdobeProductCategoryCatalogueMySqlTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration test.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function category_catalogue_tables_exist_on_mysql(): void
    {
        $this->assertTrue(Schema::hasTable('adobe_product_categories'));
        $this->assertTrue(Schema::hasTable('adobe_product_category_catalogue_states'));
    }

    #[Test]
    public function mysql_rejects_cross_workspace_category_account_reference(): void
    {
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other workspace '.Str::random(6),
            'is_default' => false,
        ]);

        $this->expectException(QueryException::class);

        DB::table('adobe_product_categories')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $otherWorkspace->id,
            'connector_account_id' => $account->id,
            'external_category_id' => '15',
            'name' => 'Cross-workspace',
            'level' => 2,
            'position' => 1,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function mysql_rejects_cross_workspace_catalogue_state_account_reference(): void
    {
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other workspace '.Str::random(6),
            'is_default' => false,
        ]);

        $this->expectException(QueryException::class);

        DB::table('adobe_product_category_catalogue_states')->insert([
            'workspace_id' => $otherWorkspace->id,
            'connector_account_id' => $account->id,
            'category_count' => 0,
            'target_context' => json_encode(['base_url' => 'https://shop.example.com', 'store_code' => 'default']),
            'last_successful_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function mysql_rejects_duplicate_category_identity_within_same_account(): void
    {
        $account = $this->createConnectorAccount();
        $row = [
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_category_id' => '15',
            'name' => 'First',
            'level' => 2,
            'position' => 1,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('adobe_product_categories')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('adobe_product_categories')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
            'name' => 'Duplicate',
        ]);
    }
}
