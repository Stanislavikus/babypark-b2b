<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTagRelationEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_tags_eager_loads_without_exception(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-001',
            'name' => 'Eager load product',
            'is_active' => true,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'eager-tag',
        ]);

        $product->tags()->attach($tag->id);

        $loaded = Product::query()->with('tags')->findOrFail($product->id);

        $this->assertCount(1, $loaded->tags);
        $this->assertTrue($loaded->tags->first()->is($tag));
    }

    public function test_tag_with_count_products_returns_correct_count(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'count-tag',
        ]);

        $productA = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-002',
            'name' => 'Product A',
            'is_active' => true,
        ]);

        $productB = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-003',
            'name' => 'Product B',
            'is_active' => true,
        ]);

        $productA->tags()->attach($tag->id);
        $productB->tags()->attach($tag->id);

        $loaded = Tag::query()->withCount('products')->findOrFail($tag->id);

        $this->assertSame(2, $loaded->products_count);
    }

    public function test_where_has_tags_filter_works(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'filter-tag',
        ]);

        $tagged = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-004',
            'name' => 'Tagged product',
            'is_active' => true,
        ]);

        Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-005',
            'name' => 'Untagged product',
            'is_active' => true,
        ]);

        $tagged->tags()->attach($tag->id);

        $results = Product::query()
            ->whereHas('tags', fn ($query) => $query->whereKey($tag->id))
            ->pluck('id');

        $this->assertTrue($results->contains($tagged->id));
        $this->assertCount(1, $results);
    }

    public function test_attach_on_loaded_product_stamps_pivot_workspace_id(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EAGER-TAG-006',
            'name' => 'Pivot workspace product',
            'is_active' => true,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'pivot-tag',
        ]);

        $product->load('tags');
        $product->tags()->attach($tag->id);

        $this->assertDatabaseHas('product_tag', [
            'product_id' => $product->id,
            'tag_id' => $tag->id,
            'workspace_id' => $workspace->id,
        ]);
    }
}
