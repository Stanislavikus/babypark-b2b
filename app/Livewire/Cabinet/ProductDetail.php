<?php

namespace App\Livewire\Cabinet;

use App\Models\Product;
use App\Services\PriceResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class ProductDetail extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        $contractor = Auth::guard('contractor')->user();

        $hasPricing = $product->variants()
            ->whereHas('prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->exists();

        if (! $hasPricing) {
            abort(404);
        }

        $this->product = $product->load([
            'category',
            'variants.stocks',
            'variants.productPrices' => fn ($q) => $q->where(
                fn ($w) => $w->where('contractor_id', $contractor->id)->orWhereNull('contractor_id')
            ),
        ]);
    }

    public function render()
    {
        $contractor = Auth::guard('contractor')->user();
        $resolver = new PriceResolver;

        // Resolve every variant's prices up front so the view never reads product_prices directly.
        $variantPrices = [];
        foreach ($this->product->variants as $variant) {
            $variantPrices[$variant->id] = [
                'contract' => $resolver->contractPrice($variant, $contractor),
                'list' => $resolver->listPrice($variant),
            ];
        }

        return view('livewire.cabinet.product-detail', [
            'contractor' => $contractor,
            'variantPrices' => $variantPrices,
        ]);
    }
}
