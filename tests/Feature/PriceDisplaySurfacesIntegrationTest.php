<?php

namespace Tests\Feature;

use App\Enums\PriceDisplayMode;
use App\Services\Pricing\Inspection\PriceInspectorContext;
use App\Services\Pricing\Inspection\PriceInspectorPresenter;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ProductPricingSummary;
use App\Support\Pricing\CustomerFacingPriceLabel;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceDisplaySurfacesIntegrationTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_price_inspector_uses_presenter_for_all_three_modes(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);
        $list = $this->createPriceList($workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 90.0, vatRate: 20.0);

        $presenter = app(PriceInspectorPresenter::class);
        $resolver = app(PriceResolver::class);
        $result = $resolver->resolveWithTrace($variant, $customer, 1);
        $context = new PriceInspectorContext($customer, $variant, 1, now()->toImmutable(), null);

        foreach ([
            PriceDisplayMode::TaxInclusivePrimary,
            PriceDisplayMode::TaxExclusivePrimary,
            PriceDisplayMode::BothEqual,
        ] as $mode) {
            $workspace->update(['default_price_display_mode' => $mode]);
            $presentation = $presenter->present($result, $context);

            $this->assertStringContainsString('₴', (string) $presentation->priceSummary);
            $this->assertStringContainsString('подат', mb_strtolower((string) $presentation->priceSummary));
        }
    }

    public function test_admin_product_table_uses_presenter(): void
    {
        $workspace = $this->defaultWorkspace();
        $workspace->update(['default_price_display_mode' => PriceDisplayMode::TaxExclusivePrimary]);
        $variant = $this->createVariant($workspace, basePriceCache: 90.0);
        $product = $variant->product;

        $label = app(ProductPricingSummary::class)->formatDefaultSalePrice($product->load('variants'));

        $this->assertSame('90,00 ₴ без податку', $label);
    }

    public function test_cabinet_catalog_uses_presenter(): void
    {
        $workspace = $this->defaultWorkspace();
        $workspace->update(['default_price_display_mode' => PriceDisplayMode::TaxInclusivePrimary]);
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);
        $list = $this->createPriceList($workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 90.0, vatRate: 20.0);

        $summary = app(ProductPricingSummary::class);
        $display = $summary->resolveVariantDisplay($variant, $customer);
        $label = CustomerFacingPriceLabel::forDisplay($display);

        $this->assertSame('108,00 ₴ з податком', $label);
    }

    public function test_price_list_item_input_labels_remain_net_semantics(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/PriceListResource/RelationManagers/ItemsRelationManager.php'));

        $this->assertStringContainsString("->label('Ціна без податку')", $source);
        $this->assertStringContainsString("->label('Акційна ціна без податку')", $source);
        $this->assertStringContainsString("->label('Ставка податку')", $source);
    }
}
