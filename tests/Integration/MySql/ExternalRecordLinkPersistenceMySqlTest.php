<?php

namespace Tests\Integration\MySql;

use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ExternalRecordLinkPersistenceMySqlTest extends TestCase
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
    public function mysql_version_supports_check_constraint_enforcement(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;
        $this->assertMatchesRegularExpression('/^8\.0\.(\d+)/', (string) $version);

        preg_match('/^8\.0\.(\d+)/', (string) $version, $matches);
        $this->assertGreaterThanOrEqual(16, (int) ($matches[1] ?? 0), 'MySQL VERSION(): '.$version);
    }

    #[Test]
    public function stage_3a_external_record_link_migrations_roll_back_and_reapply(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;

        $this->assertTrue(Schema::hasTable('external_record_links'));

        Artisan::call('migrate:rollback', [
            '--step' => 1,
        ]);

        $this->assertFalse(Schema::hasTable('external_record_links'));

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasTable('external_record_links'));

        $this->assertNotEmpty($version);
    }

    #[Test]
    public function mysql_rejects_invalid_xor_and_duplicate_associations(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'MYSQL-EXT-1',
        ]);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'MYSQL-VAR-1',
        ]);

        try {
            DB::table('external_record_links')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'external_identifier' => 'BAD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected XOR violation.');
        } catch (QueryException) {
            // expected
        }

        try {
            ExternalRecordLink::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'external_identifier' => 'MYSQL-EXT-1',
            ]);
            $this->fail('Expected duplicate product association rejection.');
        } catch (QueryException) {
            // expected
        }

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'MYSQL-EXT-2',
        ]);
    }

    private function createProduct(Workspace $workspace): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Product',
            'is_active' => true,
        ]);
    }

    private function createVariant(Workspace $workspace, Product $product): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-'.Str::random(8),
            'is_active' => true,
        ]);
    }
}
