<?php

namespace App\Livewire\Cabinet;

use App\Enums\CatalogPriceDisplayStatus;
use App\Models\Product;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Services\Pricing\ProductPricingSummary;
use App\Support\Pricing\CustomerFacingPriceLabel;
use App\Support\Pricing\VariantPriceDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class ProductDetail extends Component
{
    public Product $product;

    /** @var array<int, array<int, array{location_name: string, status_label: string, status_class: string}>> */
    public array $variantStockDisplay = [];

    /** @var array<int, int> */
    public array $variantNetAvailability = [];

    /** @var array<int, VariantPriceDisplay> */
    public array $variantPriceDisplay = [];

    /** @var array<int, string> */
    public array $variantPriceLabels = [];

    public function mount(Product $product): void
    {
        $customer = Auth::guard('customer')->user();
        $summary = app(ProductPricingSummary::class);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        $this->product = $product->load([
            'category',
            'variants.stocks.inventoryLocation',
        ]);

        $activeVariants = $this->product->variants->where('is_active', true);
        $displaysByVariant = $activeVariants->mapWithKeys(
            fn ($variant) => [$variant->id => $summary->resolveVariantDisplay($variant, $customer, 1, $snapshot)]
        );

        $hasVisibleContent = $displaysByVariant->contains(
            fn (VariantPriceDisplay $display) => $display->status !== CatalogPriceDisplayStatus::Unavailable
        );

        if (! $hasVisibleContent) {
            abort(404);
        }

        $threshold = $product->category?->stock_display_threshold ?? 10;
        $resolver = app(AvailabilityResolver::class);

        foreach ($activeVariants as $variant) {
            $priceDisplay = $displaysByVariant->get($variant->id);

            if ($priceDisplay->status === CatalogPriceDisplayStatus::Unavailable) {
                continue;
            }

            $this->variantPriceDisplay[$variant->id] = $priceDisplay;
            $this->variantPriceLabels[$variant->id] = CustomerFacingPriceLabel::forDisplay($priceDisplay);

            $rows = [];

            foreach ($variant->stocks as $stock) {
                $qty = (int) $stock->quantity;

                if ($qty > $threshold) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'В наявності',
                        'status_class' => 'font-medium text-green-700',
                    ];
                } elseif ($qty > 0) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => "Залишилось {$qty} шт",
                        'status_class' => 'font-medium text-yellow-700',
                    ];
                } elseif ($stock->expected_date) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'Очікується '.$stock->expected_date->format('d.m'),
                        'status_class' => 'text-blue-700',
                    ];
                } else {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'Немає в наявності',
                        'status_class' => 'text-gray-400',
                    ];
                }
            }

            $this->variantStockDisplay[$variant->id] = $rows;
            $this->variantNetAvailability[$variant->id] = $resolver->netAvailable($variant);
        }
    }

    public function render()
    {
        return view('livewire.cabinet.product-detail', [
            'customer' => Auth::guard('customer')->user(),
        ]);
    }
}
