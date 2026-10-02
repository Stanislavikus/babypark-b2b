<?php

namespace Tests\Feature\Sync;

use App\Enums\AdobeProductCategoryAssignmentState;
use App\Models\AdobeProductCategoryAssignment;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class MagentoCategoryRelationPersistenceTest extends TestCase
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
    public function schema_exposes_workspace_safe_category_mapping_and_assignment_keys(): void
    {
        $this->assertTrue(Schema::hasTable('connector_category_mappings'));
        $this->assertTrue(Schema::hasTable('adobe_product_category_assignments'));
        $this->assertTrue($this->indexExists('categories', 'categories_workspace_id_id_unique'));
        $this->assertTrue($this->indexExists('external_record_links', 'erl_ws_account_id_unique'));
        $this->assertTrue($this->indexExists('connector_category_mappings', 'ccm_ws_account_category_unique'));
        $this->assertTrue($this->indexExists('adobe_product_category_assignments', 'apca_relation_unique'));

        $this->assertFalse(Schema::hasColumn('adobe_product_category_assignments', 'category_id'));
        $this->assertFalse(Schema::hasColumn('adobe_product_category_assignments', 'product_id'));
        $this->assertFalse(Schema::hasColumn('adobe_product_category_assignments', 'product_variant_id'));
    }

    #[Test]
    public function multiple_local_categories_may_collapse_to_one_external_category(): void
    {
        $account = $this->createConnectorAccount();
        $first = $this->createCategory($account->workspace_id, 'Local A');
        $second = $this->createCategory($account->workspace_id, 'Local B');

        foreach ([$first, $second] as $category) {
            ConnectorCategoryMapping::withoutWorkspaceScope()->create([
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->id,
                'category_id' => $category->id,
                'external_category_id' => '7',
            ]);
        }

        $this->assertSame(2, ConnectorCategoryMapping::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('external_category_id', '7')
            ->count());
    }

    #[Test]
    public function one_local_category_cannot_have_two_targets_for_same_account(): void
    {
        $account = $this->createConnectorAccount();
        $category = $this->createCategory($account->workspace_id, 'Local A');

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '7',
        ]);

        $this->expectException(QueryException::class);

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '8',
        ]);
    }

    #[Test]
    public function category_mapping_rejects_cross_workspace_category(): void
    {
        $account = $this->createConnectorAccount();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign category workspace',
            'is_default' => false,
        ]);
        $foreignCategory = $this->createCategory($foreignWorkspace->id, 'Foreign');

        $this->expectException(QueryException::class);

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'category_id' => $foreignCategory->id,
            'external_category_id' => '7',
        ]);
    }

    #[Test]
    public function assignment_is_unique_per_exact_external_product_relation(): void
    {
        $account = $this->createConnectorAccount();
        $link = $this->createVariantLink($account->workspace_id, $account->id);

        AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_record_link_id' => $link->id,
            'external_category_id' => '7',
            'state' => AdobeProductCategoryAssignmentState::Managed,
            'anchor_entity_id' => '501',
        ]);

        $this->expectException(QueryException::class);

        AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_record_link_id' => $link->id,
            'external_category_id' => '7',
            'state' => AdobeProductCategoryAssignmentState::PendingAdd,
        ]);
    }

    #[Test]
    public function assignment_rejects_external_record_link_from_another_account(): void
    {
        $account = $this->createConnectorAccount();
        $other = $this->createConnectorAccount();
        $foreignLink = $this->createVariantLink($other->workspace_id, $other->id);

        $this->expectException(QueryException::class);

        AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_record_link_id' => $foreignLink->id,
            'external_category_id' => '7',
            'state' => AdobeProductCategoryAssignmentState::PendingAdd,
        ]);
    }

    private function createCategory(string $workspaceId, string $name): Category
    {
        return Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'name' => $name,
        ]);
    }

    private function createVariantLink(string $workspaceId, string $connectorAccountId): ExternalRecordLink
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CATEGORY-'.Str::random(8),
            'name' => 'Category relation fixture',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CATEGORY-VAR-'.Str::random(8),
            'is_active' => true,
        ]);

        return ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
        ]);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list('{$table}')") as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            return $connection->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$connection->getDatabaseName(), $table, $indexName],
            ) !== [];
        }

        return false;
    }
}
