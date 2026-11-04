<?php

namespace Tests\Feature;

use App\Enums\AdobeProductCategoryAssignmentState;
use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\UserRole;
use App\Exceptions\Catalog\CategoryTreeMutationException;
use App\Models\AdobeProductCategoryAssignment;
use App\Models\AdobeProductCategoryOverride;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Catalog\CategoryDeleteImpactService;
use App\Services\Catalog\CategoryTreeMutationService;
use App\Services\Sync\ConnectorCategoryMappingSnapshotService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class CategoryDeleteTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    private WorkspaceUser $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);

        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->actor = User::query()->create([
            'name' => 'Category Delete Admin',
            'email' => 'category-delete-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Category delete manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($this->membership, $role);
    }

    #[Test]
    public function deleting_empty_category_reindexes_its_parent_siblings(): void
    {
        $before = $this->category('A', sortOrder: 0);
        $deleted = $this->category('B', sortOrder: 1);
        $after = $this->category('C', sortOrder: 2);

        app(CategoryTreeMutationService::class)->deleteSingle(
            $this->actor,
            $this->workspace,
            $deleted,
            null,
            $this->expectedImpact($deleted),
        );

        $this->assertDatabaseMissing('categories', ['id' => $deleted->id]);
        $this->assertSame(0, $before->fresh()->sort_order);
        $this->assertSame(1, $after->fresh()->sort_order);
    }

    #[Test]
    public function deleting_category_moves_products_reparents_children_removes_local_mapping_and_keeps_ledger(): void
    {
        $before = $this->category('A', sortOrder: 0);
        $source = $this->category('B', sortOrder: 1);
        $after = $this->category('C', sortOrder: 2);
        $destination = $this->category('D', sortOrder: 3);
        $firstChild = $this->category('B1', $source, sortOrder: 0);
        $secondChild = $this->category('B2', $source, sortOrder: 1);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'DELETE-'.Str::random(8),
            'name' => 'Delete category product',
            'category_id' => $source->id,
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'DELETE-VAR-'.Str::random(8),
            'is_active' => true,
        ]);

        $account = $this->createConnectorAccount($this->workspace);
        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $source->id,
            'external_category_id' => '6',
        ]);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '501',
            'established_by_workspace_user_id' => $this->membership->id,
            'established_at' => now(),
        ]);
        AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'external_record_link_id' => $link->id,
            'external_category_id' => '6',
            'state' => AdobeProductCategoryAssignmentState::Managed,
            'anchor_entity_id' => '501',
        ]);
        AdobeProductCategoryOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_category_id' => '9',
        ]);

        $revisionService = app(ConnectorCategoryMappingSnapshotService::class);
        $beforeRevision = $revisionService->revision((string) $this->workspace->id, (string) $account->id);

        app(CategoryTreeMutationService::class)->deleteSingle(
            $this->actor,
            $this->workspace,
            $source,
            (int) $destination->id,
            $this->expectedImpact($source),
        );

        $this->assertDatabaseMissing('categories', ['id' => $source->id]);
        $this->assertSame($destination->id, $product->fresh()->category_id);
        $this->assertNull($firstChild->fresh()->parent_id);
        $this->assertNull($secondChild->fresh()->parent_id);
        $this->assertSame(0, $before->fresh()->sort_order);
        $this->assertSame(1, $firstChild->fresh()->sort_order);
        $this->assertSame(2, $secondChild->fresh()->sort_order);
        $this->assertSame(3, $after->fresh()->sort_order);
        $this->assertSame(4, $destination->fresh()->sort_order);

        $this->assertDatabaseMissing('connector_category_mappings', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $source->id,
        ]);
        $this->assertNotSame(
            $beforeRevision,
            $revisionService->revision((string) $this->workspace->id, (string) $account->id),
        );

        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame($link->id, $assignment->external_record_link_id);
        $this->assertSame('6', $assignment->external_category_id);
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $assignment->state);
        $this->assertDatabaseHas('adobe_product_category_overrides', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_category_id' => '9',
        ]);
    }

    #[Test]
    public function deleting_hidden_category_to_uncategorized_does_not_reveal_its_active_children(): void
    {
        $source = $this->category('Hidden', active: false, threshold: 3);
        $child = $this->category('Visible flag child', $source, active: true);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Uncategorized after delete',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        app(CategoryTreeMutationService::class)->deleteSingle(
            $this->actor,
            $this->workspace,
            $source,
            null,
            $this->expectedImpact($source),
        );

        $this->assertNull($product->fresh()->category_id);
        $this->assertNull($child->fresh()->parent_id);
        $this->assertFalse($child->fresh()->is_active);
    }

    #[Test]
    public function deleting_hidden_category_does_not_redundantly_deactivate_child_under_hidden_parent(): void
    {
        $parent = $this->category('Hidden parent', active: false);
        $source = $this->category('Hidden middle', $parent, active: false);
        $child = $this->category('Active child flag', $source, active: true);

        app(CategoryTreeMutationService::class)->deleteSingle(
            $this->actor,
            $this->workspace,
            $source,
            null,
            $this->expectedImpact($source),
        );

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertTrue($child->is_active);
    }

    #[Test]
    public function delete_rejects_stale_impact_without_mutating_category_or_product(): void
    {
        $source = $this->category('Source');
        $impact = app(CategoryDeleteImpactService::class)->impact($this->workspace, $source);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Arrived after modal opened',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        try {
            app(CategoryTreeMutationService::class)->deleteSingle(
                $this->actor,
                $this->workspace,
                $source,
                null,
                [
                    'products_count' => $impact['products_count'],
                    'children_count' => $impact['children_count'],
                    'mappings_count' => $impact['mappings_count'],
                    'fingerprint' => $impact['fingerprint'],
                ],
            );
            $this->fail('Stale delete impact must fail closed.');
        } catch (CategoryTreeMutationException $exception) {
            $this->assertSame(
                'Категорія змінилася після відкриття підтвердження. Оновіть сторінку і повторіть видалення.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('categories', ['id' => $source->id]);
        $this->assertSame($source->id, $product->fresh()->category_id);
    }

    #[Test]
    public function delete_rejects_same_count_product_swap_after_confirmation(): void
    {
        $source = $this->category('Source');
        $first = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'First product',
            'category_id' => $source->id,
            'is_active' => true,
        ]);
        $impact = app(CategoryDeleteImpactService::class)->impact($this->workspace, $source);

        $first->update(['category_id' => null]);
        $replacement = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Replacement product',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        try {
            app(CategoryTreeMutationService::class)->deleteSingle(
                $this->actor,
                $this->workspace,
                $source,
                null,
                [
                    'products_count' => $impact['products_count'],
                    'children_count' => $impact['children_count'],
                    'mappings_count' => $impact['mappings_count'],
                    'fingerprint' => $impact['fingerprint'],
                ],
            );
            $this->fail('Same-count Product replacement must invalidate delete confirmation.');
        } catch (CategoryTreeMutationException $exception) {
            $this->assertSame(
                'Категорія змінилася після відкриття підтвердження. Оновіть сторінку і повторіть видалення.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('categories', ['id' => $source->id]);
        $this->assertNull($first->fresh()->category_id);
        $this->assertSame($source->id, $replacement->fresh()->category_id);
    }

    #[Test]
    public function delete_rejects_effectively_inactive_destination_and_rolls_back(): void
    {
        $source = $this->category('Source');
        $inactiveParent = $this->category('Inactive destination parent', active: false);
        $destination = $this->category('Active flag but hidden destination', $inactiveParent, active: true);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Must stay put',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        try {
            app(CategoryTreeMutationService::class)->deleteSingle(
                $this->actor,
                $this->workspace,
                $source,
                (int) $destination->id,
                $this->expectedImpact($source),
            );
            $this->fail('Inactive destination must be rejected.');
        } catch (CategoryTreeMutationException $exception) {
            $this->assertSame(
                'Цільова категорія має бути активною категорією цього робочого простору.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('categories', ['id' => $source->id]);
        $this->assertSame($source->id, $product->fresh()->category_id);
    }

    #[Test]
    public function delete_rejects_category_from_another_workspace(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign delete workspace',
            'is_default' => false,
        ]);
        $foreign = $this->category('Foreign', workspace: $foreignWorkspace);

        $this->expectException(AuthorizationException::class);

        app(CategoryTreeMutationService::class)->deleteSingle(
            $this->actor,
            $this->workspace,
            $foreign,
            null,
            [
                'products_count' => 0,
                'children_count' => 0,
                'mappings_count' => 0,
                'fingerprint' => 'foreign',
            ],
        );
    }

    #[Test]
    public function delete_impact_exposes_only_valid_destinations_and_warns_about_missing_magento_mapping(): void
    {
        $source = $this->category('Source');
        $destination = $this->category('Destination');
        $inactive = $this->category('Inactive', active: false);
        $account = $this->createConnectorAccount($this->workspace);

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $source->id,
            'external_category_id' => '6',
        ]);

        $service = app(CategoryDeleteImpactService::class);
        $impact = $service->impact($this->workspace, $source);
        $options = $service->destinationOptions($this->workspace, $source);

        $this->assertSame(1, $impact['mappings_count']);
        $this->assertSame(1, $impact['adobe_mappings_count']);
        $this->assertArrayHasKey('__uncategorized__', $options);
        $this->assertArrayHasKey($destination->id, $options);
        $this->assertArrayNotHasKey($source->id, $options);
        $this->assertArrayNotHasKey($inactive->id, $options);
        $this->assertSame(1, $service->missingAdobeMappingCount(
            $this->workspace,
            $source,
            (int) $destination->id,
        ));

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $destination->id,
            'external_category_id' => '8',
        ]);

        $this->assertSame(0, $service->missingAdobeMappingCount(
            $this->workspace,
            $source,
            (int) $destination->id,
        ));
    }

    private function category(
        string $name,
        ?Category $parent = null,
        bool $active = true,
        int $sortOrder = 0,
        int $threshold = 10,
        ?Workspace $workspace = null,
    ): Category {
        $workspace ??= $this->workspace;

        return Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'parent_id' => $parent?->id,
            'sort_order' => $sortOrder,
            'is_active' => $active,
            'stock_display_threshold' => $threshold,
        ]);
    }

    /** @return array{products_count:int,children_count:int,mappings_count:int,fingerprint:string} */
    private function expectedImpact(Category $category): array
    {
        $impact = app(CategoryDeleteImpactService::class)->impact($this->workspace, $category);

        return [
            'products_count' => $impact['products_count'],
            'children_count' => $impact['children_count'],
            'mappings_count' => $impact['mappings_count'],
            'fingerprint' => $impact['fingerprint'],
        ];
    }
}
