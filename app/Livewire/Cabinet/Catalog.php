<?php

namespace App\Livewire\Cabinet;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.cabinet')]
class Catalog extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $brand = '';

    /** Product ID whose photo lightbox is open; null = closed. */
    public ?int $lightboxProductId = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategory(): void { $this->resetPage(); }
    public function updatedBrand(): void { $this->resetPage(); }

    public function openPhotoLightbox(int $productId): void
    {
        $this->lightboxProductId = $productId;
    }

    public function closePhotoLightbox(): void
    {
        $this->lightboxProductId = null;
    }

    public function render()
    {
        $contractor = Auth::guard('contractor')->user();

        $query = Product::query()
            ->where('is_active', true)
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_active', true),
                'variants.prices'  => fn ($q) => $q->where('contractor_id', $contractor->id),
                'variants.stocks',
            ]);

        if (filled($this->search)) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('sku',  'like', "%{$this->search}%")
                ->orWhere('brand','like', "%{$this->search}%")
            );
        }

        if (filled($this->category)) {
            $query->where('category_id', $this->category);
        }

        if (filled($this->brand)) {
            $query->where('brand', $this->brand);
        }

        $products   = $query->orderBy('name')->paginate(24);
        $categories = Category::orderBy('name')->get();
        $brands     = Product::where('is_active', true)
            ->whereNotNull('brand')
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->distinct()->orderBy('brand')->pluck('brand');

        // Resolve lightbox product if one is open
        $lightboxProduct = $this->lightboxProductId
            ? Product::find($this->lightboxProductId)
            : null;

        return view('livewire.cabinet.catalog',
            compact('products', 'categories', 'brands', 'lightboxProduct'));
    }
}
