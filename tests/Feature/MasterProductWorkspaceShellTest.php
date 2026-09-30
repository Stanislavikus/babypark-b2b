<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductWorkspaceSummaryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterProductWorkspaceShellTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->admin = User::query()->create([
            'name' => 'Workspace shell admin',
            'email' => 'workspace-shell@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function edit_page_renders_the_visible_master_product_workspace_sections(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'WORKSPACE-001',
            'name' => 'Workspace stroller',
            'brand' => 'BabyPark',
            'description' => '<p>Workspace description</p>',
            'images' => [
                'https://example.test/media/main.jpg',
                'https://example.test/media/second.jpg',
            ],
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'WORKSPACE-001',
            'attributes' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Основна інформація')
            ->assertSee('Медіа')
            ->assertSee('Ціна')
            ->assertSee('Залишки')
            ->assertSee('Доставка та фізичні дані')
            ->assertSee('Варіанти')
            ->assertSee('Характеристики')
            ->assertSee('SEO та пошук')
            ->assertSee('Організація')
            ->assertSee('Якість даних')
            ->assertSee('Канали публікації')
            ->assertSee('Потребує уваги')
            ->assertSee('Master Product · 1С · SKU WORKSPACE-001')
            ->assertSee('Простий товар')
            ->assertSee('2 медіа · поточний Master-набір')
            ->assertSee('Інформаційно · не є готовністю конкретного каналу.');
    }

    #[Test]
    public function basic_quality_is_informational_and_tracks_only_visible_master_signals(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Draft product',
            'brand' => null,
            'description' => '<p>Draft description</p>',
            'images' => [],
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $summary = app(ProductWorkspaceSummaryService::class)->basicCompleteness($product);

        $this->assertSame(2, $summary['filled']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(40, $summary['percentage']);
        $this->assertSame(['Категорія', 'Бренд', 'Медіа'], $summary['missing']);
    }

    #[Test]
    public function variant_summary_keeps_a_single_internal_variant_visually_simple(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Simple product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $summary = app(ProductWorkspaceSummaryService::class)->variants($product);

        $this->assertSame(1, $summary['count']);
        $this->assertSame('Простий товар', $summary['label']);
        $this->assertSame([], $summary['skus']);
    }
}
