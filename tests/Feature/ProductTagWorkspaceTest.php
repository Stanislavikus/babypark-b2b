<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tag;
use App\Models\Workspace;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductTagWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_and_tag_in_same_workspace_can_be_related_with_workspace_id_on_pivot(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'TAG-PRODUCT-001',
            'name' => 'Tagged product',
            'is_active' => true,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'sale',
        ]);

        $product->tags()->attach($tag->id);

        $pivot = DB::table('product_tag')
            ->where('product_id', $product->id)
            ->where('tag_id', $tag->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertSame($workspace->id, $pivot->workspace_id);
        $this->assertTrue($product->tags()->whereKey($tag->id)->exists());
    }

    public function test_cross_workspace_attach_is_rejected_by_pivot_guard(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->firstOrFail();

        $workspaceB = Workspace::query()->create([
            'name' => 'Workspace B',
            'is_default' => false,
        ]);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceA->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'TAG-PRODUCT-002',
            'name' => 'Product in workspace A',
            'is_active' => true,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'sale',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Product and tag must belong to the same workspace.');

        $product->tags()->attach($tag->id);
    }
}
