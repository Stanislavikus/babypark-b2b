<?php

namespace Tests\Feature;

use App\Livewire\Cabinet\Catalog;
use App\Models\Contractor;
use App\Models\Price;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Services\PriceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private function typeId(string $code): int
    {
        return PriceType::query()->where('code', $code)->value('id');
    }

    private function setContractPrice(ProductVariant $variant, Contractor $contractor, string $value): void
    {
        ProductPrice::query()->create([
            'variant_id' => $variant->id,
            'contractor_id' => $contractor->id,
            'price_type_id' => $this->typeId(PriceType::CODE_CONTRACT_PRICE),
            'value' => $value,
            'currency' => 'UAH',
            'source' => '1c',
        ]);

        // The cabinet's product visibility check still keys off the prices table, so a
        // matching row must exist for the product to appear in the catalog.
        Price::query()->create([
            'contractor_id' => $contractor->id,
            'variant_id' => $variant->id,
            'price' => $value,
            'vat_rate' => 20,
            'min_quantity' => 1,
            'currency' => 'UAH',
        ]);
    }

    private function setContractorlessPrice(ProductVariant $variant, string $code, string $value): void
    {
        ProductPrice::query()->create([
            'variant_id' => $variant->id,
            'contractor_id' => null,
            'price_type_id' => $this->typeId($code),
            'value' => $value,
            'currency' => 'UAH',
            'source' => 'manual',
        ]);
    }

    /**
     * The BP-00040 scenario: a cheaper variant with no stock and a more expensive variant
     * that's actually in stock. The catalog's orderable ("Замовити") target MUST be the same
     * variant the resolver selects as cheapest — variant identity is the primary assertion,
     * because "price right, wrong variant added to cart" is the exact bug we already shipped.
     */
    public function test_catalog_price_and_orderable_variant_agree_with_cart_target(): void
    {
        $contractor = Contractor::factory()->create();

        $product = Product::factory()->create(['sku' => 'BP-00040']);

        $cheap = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'BP-00040-V1',
        ]);
        $expensive = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'BP-00040-V2',
        ]);

        // Cheaper variant — out of stock (expected later).
        $this->setContractPrice($cheap, $contractor, '800.00');
        $this->setContractorlessPrice($cheap, PriceType::CODE_LIST_PRICE, '1000.00');
        Stock::query()->create([
            'variant_id' => $cheap->id,
            'warehouse_name' => 'Склад Київ',
            'quantity' => 0,
            'reserved' => 0,
            'expected_date' => now()->addDays(10)->toDateString(),
            'expected_quantity' => 50,
        ]);

        // Pricier variant — in stock.
        $this->setContractPrice($expensive, $contractor, '1200.00');
        $this->setContractorlessPrice($expensive, PriceType::CODE_LIST_PRICE, '1200.00');
        Stock::query()->create([
            'variant_id' => $expensive->id,
            'warehouse_name' => 'Склад Київ',
            'quantity' => 250,
            'reserved' => 0,
        ]);

        $resolver = new PriceResolver;
        $resolvedVariant = $resolver->minContractPriceAcrossVariants($product->fresh(), $contractor);

        // Whatever the catalog picks as the "Замовити" target (productData[...]['firstVariant']).
        $this->actingAs($contractor, 'contractor');
        $productData = Livewire::test(Catalog::class)->viewData('productData');
        $orderableVariant = $productData[$product->id]['firstVariant'];

        // Primary assertion: same variant.
        $this->assertNotNull($resolvedVariant);
        $this->assertNotNull($orderableVariant);
        $this->assertSame($resolvedVariant->id, $orderableVariant->id);
        $this->assertSame($cheap->id, $orderableVariant->id);

        // Sanity check: necessarily true if the above holds.
        $this->assertSame(
            $resolver->contractPrice($resolvedVariant, $contractor),
            $resolver->contractPrice($orderableVariant, $contractor),
        );

        // And the displayed catalog price is the cheapest variant's contract price.
        $this->assertSame(800.0, $productData[$product->id]['maxMyPrice']);
    }

    /**
     * Margin = list price − cost of goods sold. It must go negative when cost exceeds list,
     * and stay positive otherwise — the other concrete bug class hit by hand this week.
     */
    public function test_margin_sign_flips_when_cost_exceeds_list_price(): void
    {
        $resolver = new PriceResolver;

        $negative = ProductVariant::factory()->create();
        $this->setContractorlessPrice($negative, PriceType::CODE_LIST_PRICE, '100.00');
        $this->setContractorlessPrice($negative, PriceType::CODE_COST_OF_GOODS_SOLD, '150.00');

        $positive = ProductVariant::factory()->create();
        $this->setContractorlessPrice($positive, PriceType::CODE_LIST_PRICE, '150.00');
        $this->setContractorlessPrice($positive, PriceType::CODE_COST_OF_GOODS_SOLD, '100.00');

        $negativeMargin = $resolver->margin($negative->fresh());
        $positiveMargin = $resolver->margin($positive->fresh());

        $this->assertSame('-50.00', $negativeMargin);
        $this->assertSame('50.00', $positiveMargin);
        $this->assertTrue(bccomp($negativeMargin, '0', 2) < 0);
        $this->assertTrue(bccomp($positiveMargin, '0', 2) > 0);
    }

    /**
     * MySQL (and SQLite) treat NULLs in a unique index as distinct. The generated
     * contractor_key column must restore real uniqueness for contractor-less rows, so a
     * second list_price row for the same variant+type fails instead of silently duplicating.
     */
    public function test_second_contractorless_row_for_same_variant_and_type_is_rejected(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->setContractorlessPrice($variant, PriceType::CODE_LIST_PRICE, '100.00');

        $this->expectException(QueryException::class);
        $this->setContractorlessPrice($variant, PriceType::CODE_LIST_PRICE, '200.00');
    }
}
