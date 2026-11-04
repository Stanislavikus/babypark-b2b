<?php

namespace Tests\Feature;

use App\Enums\TagBulkOperation;
use App\Exceptions\Catalog\InvalidTagBulkSelectionException;
use App\Models\Product;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Catalog\TagBulkAssignmentService;
use App\Services\Catalog\TagBulkMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class TagBulkAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private TagBulkAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->service = new TagBulkAssignmentService(chunkSize: 2);
    }

    private function createProduct(string $sku): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => $sku,
            'name' => "Product {$sku}",
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string>  $names
     * @return array<string, Tag>
     */
    private function createTags(array $names): array
    {
        $tags = [];

        foreach ($names as $name) {
            $tag = Tag::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'name' => $name,
            ]);
            $tags[$name] = $tag;
        }

        return $tags;
    }

    private function assertMetricsInvariants(TagBulkMetrics $metrics): void
    {
        $this->assertSame(
            $metrics->selectedProductCount,
            $metrics->changedProductCount + $metrics->unchangedProductCount,
        );
        $this->assertSame(
            $metrics->selectedProductCount * $metrics->selectedTagCount,
            $metrics->changedLinkCount + $metrics->noOpLinkCount,
        );
    }

    public function test_preview_add_distinguishes_products_from_links_for_multi_tag_multi_product_scenario(): void
    {
        $products = [
            $this->createProduct('BULK-A'),
            $this->createProduct('BULK-B'),
            $this->createProduct('BULK-C'),
        ];
        $tags = $this->createTags(['alpha', 'beta', 'gamma']);

        $products[0]->tags()->attach($tags['alpha']->id);
        $products[0]->tags()->attach($tags['beta']->id);
        $products[1]->tags()->attach($tags['alpha']->id);

        $metrics = $this->service->preview(
            $this->workspace->id,
            collect($products)->pluck('id')->all(),
            collect($tags)->pluck('id')->all(),
            TagBulkOperation::Add,
        );

        $this->assertMetricsInvariants($metrics);
        $this->assertSame(TagBulkOperation::Add, $metrics->operation);
        $this->assertSame(3, $metrics->selectedProductCount);
        $this->assertSame(3, $metrics->selectedTagCount);
        $this->assertSame(3, $metrics->changedProductCount);
        $this->assertSame(0, $metrics->unchangedProductCount);
        $this->assertSame(6, $metrics->changedLinkCount);
        $this->assertSame(3, $metrics->noOpLinkCount);
    }

    public function test_apply_add_inserts_only_missing_links_and_is_idempotent(): void
    {
        $product = $this->createProduct('BULK-IDEM');
        $tags = $this->createTags(['one', 'two']);
        $product->tags()->attach($tags['one']->id);

        $productIds = [$product->id];
        $tagIds = collect($tags)->pluck('id')->all();

        $first = $this->service->apply(
            $this->workspace->id,
            $productIds,
            $tagIds,
            TagBulkOperation::Add,
        );

        $this->assertMetricsInvariants($first);
        $this->assertSame(1, $first->changedProductCount);
        $this->assertSame(1, $first->changedLinkCount);
        $this->assertSame(1, $first->noOpLinkCount);
        $this->assertCount(2, $product->fresh()->tags);

        $updatedAt = $product->fresh()->updated_at;

        $second = $this->service->apply(
            $this->workspace->id,
            $productIds,
            $tagIds,
            TagBulkOperation::Add,
        );

        $this->assertSame(0, $second->changedProductCount);
        $this->assertSame(0, $second->changedLinkCount);
        $this->assertSame(2, $second->noOpLinkCount);
        $this->assertTrue($product->fresh()->updated_at->equalTo($updatedAt));
    }

    public function test_apply_remove_treats_absent_links_as_no_ops_and_is_idempotent(): void
    {
        $product = $this->createProduct('BULK-RM');
        $tags = $this->createTags(['keep', 'drop']);
        $product->tags()->attach($tags['keep']->id);

        $productIds = [$product->id];
        $tagIds = collect($tags)->pluck('id')->all();

        $first = $this->service->apply(
            $this->workspace->id,
            $productIds,
            $tagIds,
            TagBulkOperation::Remove,
        );

        $this->assertMetricsInvariants($first);
        $this->assertSame(1, $first->changedProductCount);
        $this->assertSame(1, $first->changedLinkCount);
        $this->assertSame(1, $first->noOpLinkCount);
        $this->assertCount(0, $product->fresh()->tags);

        $second = $this->service->apply(
            $this->workspace->id,
            $productIds,
            $tagIds,
            TagBulkOperation::Remove,
        );

        $this->assertSame(0, $second->changedProductCount);
        $this->assertSame(0, $second->changedLinkCount);
        $this->assertSame(2, $second->noOpLinkCount);
    }

    public function test_duplicate_ids_in_input_are_deduplicated(): void
    {
        $product = $this->createProduct('BULK-DEDUP');
        $tag = $this->createTags(['dup'])['dup'];

        $metrics = $this->service->apply(
            $this->workspace->id,
            [$product->id, $product->id, $product->id],
            [$tag->id, $tag->id],
            TagBulkOperation::Add,
        );

        $this->assertSame(1, $metrics->selectedProductCount);
        $this->assertSame(1, $metrics->selectedTagCount);
        $this->assertSame(1, $metrics->changedLinkCount);
        $this->assertCount(1, $product->fresh()->tags);
    }

    public function test_invalid_selection_reasons_are_specific(): void
    {
        $product = $this->createProduct('BULK-ERR');
        $tag = $this->createTags(['err'])['err'];
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign',
            'is_default' => false,
        ]);
        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'FOREIGN-PRODUCT',
            'name' => 'Foreign product',
            'is_active' => true,
        ]);
        $foreignTag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'foreign-tag',
        ]);

        try {
            $this->service->preview($this->workspace->id, [], [$tag->id], TagBulkOperation::Add);
            $this->fail('Expected empty products exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_EMPTY_PRODUCTS, $e->reason);
        }

        try {
            $this->service->preview($this->workspace->id, [$product->id], [], TagBulkOperation::Add);
            $this->fail('Expected empty tags exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_EMPTY_TAGS, $e->reason);
        }

        try {
            $this->service->preview($this->workspace->id, [999999], [$tag->id], TagBulkOperation::Add);
            $this->fail('Expected product not found exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_PRODUCT_NOT_FOUND, $e->reason);
        }

        try {
            $this->service->preview($this->workspace->id, [$product->id], [Str::uuid()->toString()], TagBulkOperation::Add);
            $this->fail('Expected tag not found exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_TAG_NOT_FOUND, $e->reason);
        }

        try {
            $this->service->preview($this->workspace->id, [$foreignProduct->id], [$tag->id], TagBulkOperation::Add);
            $this->fail('Expected product cross workspace exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_PRODUCT_CROSS_WORKSPACE, $e->reason);
        }

        try {
            $this->service->preview($this->workspace->id, [$product->id], [$foreignTag->id], TagBulkOperation::Add);
            $this->fail('Expected tag cross workspace exception.');
        } catch (InvalidTagBulkSelectionException $e) {
            $this->assertSame(InvalidTagBulkSelectionException::REASON_TAG_CROSS_WORKSPACE, $e->reason);
        }
    }

    public function test_changed_product_count_is_deduplicated_across_tag_chunks(): void
    {
        $product = $this->createProduct('BULK-CHUNK');
        $tags = $this->createTags(['t1', 't2', 't3', 't4']);

        $metrics = $this->service->preview(
            $this->workspace->id,
            [$product->id],
            collect($tags)->pluck('id')->all(),
            TagBulkOperation::Add,
        );

        $this->assertSame(1, $metrics->changedProductCount);
        $this->assertSame(4, $metrics->changedLinkCount);
    }

    public function test_delete_count_mismatch_rolls_back_entire_transaction(): void
    {
        $product = $this->createProduct('BULK-ROLLBACK');
        $tags = $this->createTags(['rollback']);
        $product->tags()->attach($tags['rollback']->id);

        $service = new class extends TagBulkAssignmentService
        {
            public function __construct()
            {
                parent::__construct(chunkSize: 2);
            }

            protected function deletePivotChunk(string $workspaceId, array $productChunk, array $tagChunk): int
            {
                return 0;
            }
        };

        try {
            $service->apply(
                $this->workspace->id,
                [$product->id],
                [collect($tags)->first()->id],
                TagBulkOperation::Remove,
            );
            $this->fail('Expected delete mismatch exception.');
        } catch (LogicException $e) {
            $this->assertSame('Bulk tag delete affected an unexpected number of rows.', $e->getMessage());
        }

        $this->assertTrue($product->fresh()->tags()->whereKey($tags['rollback']->id)->exists());
    }

    public function test_hundreds_of_products_scenario_completes_with_expected_metrics(): void
    {
        $tags = $this->createTags(['scale-tag']);
        $productIds = [];

        for ($i = 0; $i < 150; $i++) {
            $productIds[] = $this->createProduct('BULK-SCALE-'.$i)->id;
        }

        $metrics = (new TagBulkAssignmentService(chunkSize: 50))->apply(
            $this->workspace->id,
            $productIds,
            [collect($tags)->first()->id],
            TagBulkOperation::Add,
        );

        $this->assertMetricsInvariants($metrics);
        $this->assertSame(150, $metrics->changedProductCount);
        $this->assertSame(150, $metrics->changedLinkCount);
        $this->assertSame(
            150,
            DB::table('product_tag')->where('tag_id', collect($tags)->first()->id)->count(),
        );
    }
}
