<?php

namespace Tests\Concerns;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

trait AssertsExternalRecordLinkDatabaseContract
{
    #[Test]
    public function valid_product_subject_is_accepted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);

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
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

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
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

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
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

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
        $product = $this->createExternalRecordLinkProduct($account->workspace);

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
        $product = $this->createExternalRecordLinkProduct($account->workspace);

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
    public function exact_variant_duplicate_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-DUP',
        ]);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-DUP',
        ]);
    }

    #[Test]
    public function variant_fan_out_is_accepted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-A',
        ]);

        $second = ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-B',
        ]);

        $this->assertSame('VAR-B', $second->external_identifier);
    }

    #[Test]
    public function cross_workspace_account_foreign_key_is_rejected(): void
    {
        $accountA = $this->createConnectorAccount();
        $accountB = $this->createConnectorAccount(
            Workspace::query()->create(['name' => 'Other', 'is_default' => false]),
        );
        $product = $this->createExternalRecordLinkProduct($accountA->workspace);

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
    public function cross_workspace_product_foreign_key_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other Workspace', 'is_default' => false]);
        $foreignProduct = $this->createExternalRecordLinkProduct($otherWorkspace);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $foreignProduct->id,
            'external_identifier' => 'CROSS-PRODUCT',
        ]);
    }

    #[Test]
    public function cross_workspace_variant_foreign_key_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other Workspace', 'is_default' => false]);
        $foreignProduct = $this->createExternalRecordLinkProduct($otherWorkspace);
        $foreignVariant = $this->createExternalRecordLinkVariant($otherWorkspace, $foreignProduct);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $foreignVariant->id,
            'external_identifier' => 'CROSS-VARIANT',
        ]);
    }

    #[Test]
    public function deleting_referenced_connector_account_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DELETE-ACCOUNT',
        ]);

        $this->expectException(QueryException::class);
        ConnectorAccount::withoutWorkspaceScope()->whereKey($account->id)->forceDelete();
    }

    #[Test]
    public function deleting_referenced_product_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DELETE-PRODUCT',
        ]);

        $this->expectException(QueryException::class);
        Product::withoutWorkspaceScope()->whereKey($product->id)->forceDelete();
    }

    #[Test]
    public function deleting_referenced_variant_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'EXT-DELETE-VARIANT',
        ]);

        $this->expectException(QueryException::class);
        ProductVariant::withoutWorkspaceScope()->whereKey($variant->id)->forceDelete();
    }

    #[Test]
    public function deleting_referenced_workspace_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-DELETE-WORKSPACE',
        ]);

        $this->expectException(QueryException::class);
        Workspace::query()->whereKey($account->workspace_id)->delete();
    }

    #[Test]
    public function model_rejects_both_subject_ids(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

        $this->expectException(InvalidArgumentException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'MODEL-BOTH',
        ]);
    }

    #[Test]
    public function model_rejects_neither_subject_id(): void
    {
        $account = $this->createConnectorAccount();

        $this->expectException(InvalidArgumentException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_identifier' => 'MODEL-NEITHER',
        ]);
    }

    protected function createExternalRecordLinkProduct(Workspace $workspace): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Product',
            'is_active' => true,
        ]);
    }

    protected function createExternalRecordLinkVariant(Workspace $workspace, Product $product): ProductVariant
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
