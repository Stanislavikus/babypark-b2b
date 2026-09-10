<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\TagResource\Pages\EditTag;
use App\Filament\Resources\TagResource\Pages\ListTags;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProductFields\ProductColumnVisibility;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProductClassificationUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();

        $this->admin = User::query()->create([
            'name' => 'Classification Admin',
            'email' => 'classification-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'CLS-'.Str::upper(Str::random(6)),
            'name' => 'Classification product',
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_toggleable_columns_include_classification_fields_only_for_admin(): void
    {
        $adminColumns = ProductColumnVisibility::toggleableColumns('admin');
        $cabinetColumns = ProductColumnVisibility::toggleableColumns('cabinet');

        $this->assertContains('merchant_type', $adminColumns);
        $this->assertContains('tags', $adminColumns);
        $this->assertNotContains('merchant_type', $cabinetColumns);
        $this->assertNotContains('tags', $cabinetColumns);
    }

    public function test_merchant_type_saves_via_form_and_appears_on_view_page(): void
    {
        $product = $this->createProduct(['merchant_type' => null]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['merchant_type' => '  Коляска  '])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Коляска', $product->fresh()->merchant_type);

        Livewire::actingAs($this->admin)
            ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Класифікація')
            ->assertSee('Внутрішній тип товару')
            ->assertSee('Коляска');
    }

    public function test_tags_can_be_attached_and_detached_via_product_form(): void
    {
        $product = $this->createProduct();
        $tagA = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'sale',
        ]);
        $tagB = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'featured',
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['tags' => [$tagA->id, $tagB->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertCount(2, $product->tags);
        $this->assertTrue($product->tags->pluck('id')->contains($tagA->id));
        $this->assertTrue($product->tags->pluck('id')->contains($tagB->id));

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['tags' => [$tagA->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertCount(1, $product->tags);
        $this->assertTrue($product->tags->first()->is($tagA));

        Livewire::actingAs($this->admin)
            ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Теги')
            ->assertSee('sale');
    }

    public function test_inline_tag_creation_uses_tag_manager_and_rejects_duplicate_name(): void
    {
        $product = $this->createProduct();

        Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Опт',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callFormComponentAction('tags', 'createOption', ['name' => 'опт']);

        $this->assertSame(
            1,
            Tag::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count(),
        );

        $errorMessages = collect($component->errors()->toArray())->flatten()->all();

        $this->assertContains('Тег із такою назвою вже існує.', $errorMessages);
    }

    public function test_inline_tag_creation_creates_tag_in_product_workspace(): void
    {
        $product = $this->createProduct();

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callFormComponentAction('tags', 'createOption', ['name' => 'new-inline-tag'])
            ->assertHasNoFormComponentActionErrors();

        $tag = Tag::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'new-inline-tag')
            ->first();

        $this->assertNotNull($tag);
    }

    public function test_cross_workspace_tag_cannot_be_attached_through_product_form(): void
    {
        $product = $this->createProduct();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign workspace',
            'is_default' => false,
        ]);
        $foreignTag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'foreign-tag',
        ]);

        $this->expectException(DomainException::class);

        $product->tags()->attach($foreignTag->id);
    }

    public function test_tags_filter_returns_products_matching_at_least_one_selected_tag(): void
    {
        $tagA = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'alpha',
        ]);
        $tagB = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'beta',
        ]);

        $productA = $this->createProduct(['sku' => 'CLS-FILTER-A', 'name' => 'Alpha product']);
        $productB = $this->createProduct(['sku' => 'CLS-FILTER-B', 'name' => 'Beta product']);
        $productC = $this->createProduct(['sku' => 'CLS-FILTER-C', 'name' => 'Untagged product']);

        $productA->tags()->attach($tagA->id);
        $productB->tags()->attach($tagB->id);

        $component = Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->filterTable('tags', [$tagA->id, $tagB->id]);

        $visibleSkus = collect($component->instance()->getTableRecords()->items())
            ->pluck('sku')
            ->all();

        $this->assertContains('CLS-FILTER-A', $visibleSkus);
        $this->assertContains('CLS-FILTER-B', $visibleSkus);
        $this->assertNotContains('CLS-FILTER-C', $visibleSkus);
    }

    public function test_merchant_type_filter_returns_matching_products(): void
    {
        $this->createProduct(['sku' => 'CLS-MT-A', 'merchant_type' => 'Коляска']);
        $this->createProduct(['sku' => 'CLS-MT-B', 'merchant_type' => 'Автокрісло']);
        $this->createProduct(['sku' => 'CLS-MT-C', 'merchant_type' => null]);

        $component = Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->filterTable('merchant_type', ['Коляска']);

        $visibleSkus = collect($component->instance()->getTableRecords()->items())
            ->pluck('sku')
            ->all();

        $this->assertSame(['CLS-MT-A'], $visibleSkus);
    }

    public function test_tag_resource_product_count_excludes_other_workspace_products(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign workspace',
            'is_default' => false,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'scoped-count',
        ]);

        $localProduct = $this->createProduct(['sku' => 'CLS-COUNT-LOCAL']);
        $localProduct->tags()->attach($tag->id);

        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'CLS-COUNT-FOREIGN',
            'name' => 'Foreign product',
            'is_active' => true,
        ]);
        $foreignTag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'foreign-only',
        ]);
        $foreignProduct->tags()->attach($foreignTag->id);

        $loaded = Tag::query()->withCount('products')->findOrFail($tag->id);

        $this->assertSame(1, $loaded->products_count);
    }

    public function test_tag_resource_blocks_table_delete_when_tag_is_attached(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'attached-table',
        ]);
        $product = $this->createProduct();
        $product->tags()->attach($tag->id);

        Livewire::actingAs($this->admin)
            ->test(ListTags::class)
            ->callTableAction('delete', $tag)
            ->assertNotified();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_tag_resource_blocks_header_delete_when_tag_is_attached(): void
    {
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'attached-header',
        ]);
        $product = $this->createProduct();
        $product->tags()->attach($tag->id);

        Livewire::actingAs($this->admin)
            ->test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->callAction('delete')
            ->assertNotified();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_tag_resource_allows_delete_for_unused_tag_from_table_and_edit_page(): void
    {
        $tableTag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'unused-table',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListTags::class)
            ->callTableAction('delete', $tableTag);

        $this->assertDatabaseMissing('tags', ['id' => $tableTag->id]);

        $headerTag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'unused-header',
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditTag::class, ['record' => $headerTag->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('tags', ['id' => $headerTag->id]);
    }

    public function test_tag_resource_has_no_bulk_delete_actions(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ListTags::class);

        $this->assertSame([], $component->instance()->getTable()->getBulkActions());
    }

    public function test_tags_table_column_rendering_does_not_lazy_load_tags(): void
    {
        Model::preventLazyLoading();

        try {
            $tag = Tag::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'name' => 'lazy-load-tag',
            ]);

            foreach (range(1, 3) as $index) {
                $product = $this->createProduct([
                    'sku' => 'CLS-LAZY-'.$index,
                    'name' => 'Lazy product '.$index,
                ]);
                $product->tags()->attach($tag->id);
            }

            $component = Livewire::actingAs($this->admin)
                ->test(ListProducts::class);

            foreach ($component->instance()->getTableRecords() as $record) {
                $this->assertIsString($record->tags->pluck('name')->implode(', '));
            }
        } finally {
            Model::preventLazyLoading(false);
        }
    }
}
