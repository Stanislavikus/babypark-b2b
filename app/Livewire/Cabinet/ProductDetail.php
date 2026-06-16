<?php

namespace App\Livewire\Cabinet;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class ProductDetail extends Component
{
    public Product $product;

    public bool $lightboxOpen = false;

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
            'variants.prices' => fn ($q) => $q->where('contractor_id', $contractor->id),
        ]);
    }

    public function openLightbox(): void
    {
        $this->lightboxOpen = true;
    }

    public function closeLightbox(): void
    {
        $this->lightboxOpen = false;
    }

    public function render()
    {
        return view('livewire.cabinet.product-detail', [
            'contractor' => Auth::guard('contractor')->user(),
        ]);
    }
}
