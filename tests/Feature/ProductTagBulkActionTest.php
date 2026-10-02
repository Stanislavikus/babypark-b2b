<?php

namespace Tests\Feature;

use App\Enums\TagBulkOperation;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\TagBulkAssignmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTagBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();

        $this->admin = User::query()->create([
            'name' => 'Bulk Tag Admin',
            'email' => 'bulk-tag-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function createProduct(string $sku, bool $isActive = true): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => $sku,
            'name' => "Product {$sku}",
            'is_active' => $isActive,
        ]);
    }

    public function test_bulk_add_action_applies_tags_to_selected_products(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'bulk-add',
        ]);
        $productA = $this->createProduct('UI-BULK-A');
        $productB = $this->createProduct('UI-BULK-B');

        Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->mountTableBulkAction('add_tags', [$productA, $productB])
            ->setTableBulkActionData(['tag_ids' => [$tag->id]])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertTrue($productA->fresh()->tags()->whereKey($tag->id)->exists());
        $this->assertTrue($productB->fresh()->tags()->whereKey($tag->id)->exists());
    }

    public function test_bulk_remove_action_removes_tags_from_selected_products(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'bulk-remove',
        ]);
        $product = $this->createProduct('UI-BULK-RM');
        $product->tags()->attach($tag->id);

        Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->mountTableBulkAction('remove_tags', [$product])
            ->setTableBulkActionData(['tag_ids' => [$tag->id]])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertFalse($product->fresh()->tags()->whereKey($tag->id)->exists());
    }

    public function test_bulk_select_all_matching_filter_includes_only_matching_products_across_pages(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'filter-bulk',
        ]);

        $activeProducts = collect();
        for ($i = 0; $i < 12; $i++) {
            $activeProducts->push($this->createProduct('UI-FILTER-'.$i, isActive: true));
        }

        $inactiveOnPage = $this->createProduct('UI-FILTER-INACTIVE-PAGE', isActive: false);
        $inactiveOffPage = $this->createProduct('UI-FILTER-INACTIVE-OFF', isActive: false);

        $component = Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->set('tableFilters', [
                'status' => [
                    'value' => 'active',
                ],
            ]);

        $keys = $component->instance()->getAllSelectableTableRecordKeys();
        $component->set('selectedTableRecords', $keys);

        $this->assertCount(12, $keys);
        $this->assertNotContains((string) $inactiveOnPage->id, $keys);
        $this->assertNotContains((string) $inactiveOffPage->id, $keys);

        $component
            ->mountTableBulkAction('add_tags', $activeProducts->all())
            ->setTableBulkActionData(['tag_ids' => [$tag->id]])
            ->callMountedTableBulkAction()
            ->assertNotified();

        foreach ($activeProducts as $product) {
            $this->assertTrue(
                $product->fresh()->tags()->whereKey($tag->id)->exists(),
                "Expected tag on product {$product->sku}",
            );
        }

        $this->assertFalse($inactiveOnPage->fresh()->tags()->whereKey($tag->id)->exists());
        $this->assertFalse($inactiveOffPage->fresh()->tags()->whereKey($tag->id)->exists());
    }

    public function test_bulk_action_invalid_selection_shows_validation_without_partial_changes(): void
    {
        $product = $this->createProduct('UI-BULK-INVALID');

        Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->mountTableBulkAction('add_tags', [$product])
            ->setTableBulkActionData(['tag_ids' => [Str::uuid()->toString()]])
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['tag_ids.0'])
            ->assertTableBulkActionMounted('add_tags');

        $this->assertAuthenticatedAs($this->admin);
        $this->assertCount(0, $product->fresh()->tags);
    }

    public function test_bulk_action_result_notification_reflects_apply_not_preview(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'result-tag',
        ]);
        $product = $this->createProduct('UI-BULK-RESULT');
        $product->tags()->attach($tag->id);

        $preview = app(TagBulkAssignmentService::class)->preview(
            $this->workspace->id,
            [$product->id],
            [$tag->id],
            TagBulkOperation::Add,
        );

        $this->assertSame(0, $preview->changedLinkCount);

        Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->mountTableBulkAction('add_tags', [$product])
            ->setTableBulkActionData(['tag_ids' => [$tag->id]])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertCount(1, $product->fresh()->tags);
    }
}
