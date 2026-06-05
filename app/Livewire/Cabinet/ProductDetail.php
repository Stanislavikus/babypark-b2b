<?php

namespace App\Livewire\Cabinet;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class ProductDetail extends Component
{
    public Product $product;

    public ?int $lightboxIndex = null;

    public function mount(Product $product): void
    {
        $contractor = Auth::guard('contractor')->user();

        // Client may only see products where their price exists
        $hasPricing = $product->variants()
            ->whereHas('prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->exists();

        if (! $hasPricing) {
            abort(404);
        }

        $this->product = $product->load([
            'category',
            'variants.stocks',
            'variants.prices' => fn ($q) => $q->where('contractor_id', $contractor->id),
        ]);
    }

    public function openLightbox(int $index): void
    {
        $this->lightboxIndex = $index;
    }

    public function closeLightbox(): void
    {
        $this->lightboxIndex = null;
    }

    public function render()
    {
        return view('livewire.cabinet.product-detail', [
            'contractor' => Auth::guard('contractor')->user(),
        ]);
    }
}
