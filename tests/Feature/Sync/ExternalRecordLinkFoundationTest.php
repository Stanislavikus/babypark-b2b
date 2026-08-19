<?php

namespace Tests\Feature\Sync;

use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ExternalRecordLinkFoundationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithWorkspaceRbac;
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
            'external_identifier' => 'EXT-1',
        ]);

        $this->assertDatabaseHas('external_record_links', ['id' => $link->id]);
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
            'external_identifier' => 'EXT-V-1',
        ]);

        $this->assertDatabaseHas('external_record_links', ['id' => $link->id]);
    }

    #[Test]
    public function model_rejects_both_subjects(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        $this->expectException(InvalidArgumentException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'EXT-BOTH',
        ]);
    }

    #[Test]
    public function model_rejects_neither_subject(): void
    {
        $account = $this->createConnectorAccount();

        $this->expectException(InvalidArgumentException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_identifier' => 'EXT-NONE',
        ]);
    }

    #[Test]
    public function direct_db_insert_rejects_invalid_xor_subjects(): void
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
            'external_identifier' => 'DB-BOTH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function direct_db_update_into_invalid_xor_is_rejected(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        $linkId = (string) Str::uuid();
        DB::table('external_record_links')->insert([
            'id' => $linkId,
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'external_identifier' => 'DB-UPDATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('external_record_links')->where('id', $linkId)->update([
            'product_variant_id' => $variant->id,
        ]);
    }

    #[Test]
    public function exact_product_duplicate_is_rejected_and_fan_out_is_allowed(): void
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

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-B',
        ]);

        $this->expectException(QueryException::class);
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'EXT-A',
        ]);
    }

    #[Test]
    public function exact_variant_duplicate_is_rejected_and_fan_out_is_allowed(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);
        $variant = $this->createVariant($account->workspace, $product);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-A',
        ]);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-B',
        ]);

        $this->expectException(QueryException::class);
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'VAR-A',
        ]);
    }

    #[Test]
    public function cross_workspace_account_fk_is_rejected(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);
        $accountB = $this->createConnectorAccount($workspaceB);
        $productA = $this->createProduct($workspaceA);

        $this->expectException(QueryException::class);
        DB::table('external_record_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceA->id,
            'connector_account_id' => $accountB->id,
            'product_id' => $productA->id,
            'product_variant_id' => null,
            'external_identifier' => 'CROSS-ACCOUNT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function deleting_referenced_product_is_restricted(): void
    {
        $account = $this->createConnectorAccount();
        $product = $this->createProduct($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'DEL-TEST',
        ]);

        $this->expectException(QueryException::class);
        $product->delete();
    }

    #[Test]
    public function belongs_to_workspace_scopes_reads(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);
        $account = $this->createConnectorAccount($workspaceA);
        $product = $this->createProduct($workspaceA);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceA->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'SCOPE',
        ]);

        $context = app(WorkspaceContext::class);
        $reflection = new \ReflectionProperty(WorkspaceContext::class, 'current');
        $reflection->setAccessible(true);
        $reflection->setValue($context, $workspaceB);

        $this->assertNull(ExternalRecordLink::query()->find($link->id));
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
