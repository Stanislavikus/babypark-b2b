<?php

namespace Tests\Feature\Sync;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ExternalRecordLinkPersistenceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function valid_product_subject_is_accepted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-PRODUCT-1',
        ]);

        $this->assertSame('EXT-PRODUCT-1', $link->external_identifier);
    }

    #[Test]
    public function valid_variant_subject_is_accepted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'EXT-VARIANT-1',
        ]);

        $this->assertSame('EXT-VARIANT-1', $link->external_identifier);
    }

    #[Test]
    public function direct_db_insert_with_both_subjects_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        $this->expectException(QueryException::class);

        DB::table('external_record_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'INVALID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function direct_db_insert_with_neither_subject_is_rejected(): void
    {
        $account = $this->createConnectorAccount();

        $this->expectException(QueryException::class);

        DB::table('external_record_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => null,
            'product_variant_id' => null,
            'external_identifier' => 'INVALID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function update_into_invalid_xor_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-1',
        ]);

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE external_record_links SET product_id = ?, product_variant_id = ? WHERE id = ?',
            [$product->id, $variant->id, $link->id],
        );
    }

    #[Test]
    public function exact_product_duplicate_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DUP',
        ]);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DUP',
        ]);
    }

    #[Test]
    public function product_fan_out_is_accepted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-A',
        ]);

        $second = ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-B',
        ]);

        $this->assertSame('EXT-B', $second->external_identifier);
    }

    #[Test]
    public function cross_workspace_account_foreign_key_is_rejected(): void
    {
        $accountA = $this->createConnectorAccount();
        $accountB = $this->createConnectorAccount(
            Workspace::query()->create(['name' => 'Other', 'is_default' => false]),
        );
        $product = $this->createProduct($accountA->workspace);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $accountA->workspace_id,
            'connector_account_id' => $accountB->id,
            'product_id' => $product->id,
            'external_identifier' => 'CROSS-ACCOUNT',
        ]);
    }

    #[Test]
    public function deleting_referenced_connector_account_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DELETE',
        ]);

        $this->expectException(QueryException::class);
        ConnectorAccount::withoutWorkspaceScope()->whereKey($account->id)->forceDelete();
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
