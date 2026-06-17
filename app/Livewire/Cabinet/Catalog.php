<?php

namespace App\Livewire\Cabinet;

use App\Enums\ReservationStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Support\SessionCart;
use Carbon\Carbon;
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

    /** 'cards' or 'table' */
    #[Url]
    public string $viewMode = 'cards';

    /** Margin column display format: 'percent' or 'uah' */
    public string $marginFormat = 'percent';

    /** Quantity inputs keyed by variant_id */
    public array $quantities = [];

    /** Lightbox — only used in cards mode */
    public ?int $lightboxProductId = null;

    /** Flash message after cart/reservation action */
    public ?string $flashMessage = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategory(): void { $this->resetPage(); }
    public function updatedBrand(): void { $this->resetPage(); }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['cards', 'table']) ? $mode : 'cards';
    }

    public function toggleMarginFormat(): void
    {
        $this->marginFormat = $this->marginFormat === 'percent' ? 'uah' : 'percent';
    }

    public function incrementQty(int $variantId, int $step, int $maxQty): void
    {
        $current = (int) ($this->quantities[$variantId] ?? $step);
        $this->quantities[$variantId] = min($maxQty, $current + $step);
    }

    public function decrementQty(int $variantId, int $step, int $minQty): void
    {
        $current = (int) ($this->quantities[$variantId] ?? $minQty);
        $this->quantities[$variantId] = max($minQty, $current - $step);
    }

    public function openPhotoLightbox(int $productId): void
    {
        $this->lightboxProductId = $productId;
    }

    public function closePhotoLightbox(): void
    {
        $this->lightboxProductId = null;
    }

    /**
     * Add a product variant to the session cart ("Купити").
     */
    public function addToCart(int $variantId, int $minQty): void
    {
        $qty = max($minQty, (int) ($this->quantities[$variantId] ?? $minQty));
        SessionCart::add($variantId, $qty);

        $this->flashMessage = 'Додано до кошика';
        $this->dispatch('cart-updated');
        $this->dispatch('flash', message: $this->flashMessage);
    }

    /**
     * Create a reservation for a product variant ("Бронювати").
     */
    public function reserve(int $variantId, int $minQty): void
    {
        $contractor = Auth::guard('contractor')->user();
        $qty = max($minQty, (int) ($this->quantities[$variantId] ?? $minQty));

        Reservation::create([
            'contractor_id' => $contractor->id,
            'variant_id'    => $variantId,
            'quantity'      => $qty,
            'status'        => ReservationStatus::Active,
            'expires_at'    => now()->addHours(config('b2b.reservation_ttl_hours', 48)),
        ]);

        $this->flashMessage = 'Бронювання створено';
        $this->dispatch('flash', message: $this->flashMessage);
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
                ->where('name',  'like', "%{$this->search}%")
                ->orWhere('sku',   'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
            );
        }

        if (filled($this->category)) {
            $query->where('category_id', $this->category);
        }

        if (filled($this->brand)) {
            $query->where('brand', $this->brand);
        }

        $products = $query->orderBy('name')->paginate(24);

        // Pre-compute badge + first-variant data for each product on this page,
        // and initialise quantity defaults.
        $productData = [];
        foreach ($products as $product) {
            $threshold     = $product->category?->stock_display_threshold ?? 10;
            $activeVariants = $product->variants->where('is_active', true);
            $variantsWithPrice = $activeVariants->filter(fn ($v) => $v->prices->isNotEmpty());
            $firstVariant  = $variantsWithPrice->first();

            // Aggregate stock across ALL active variants for the badge
            $totalAvailQty  = $activeVariants->sum(fn ($v) => $v->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0)));
            $totalExpQty    = $activeVariants->sum(fn ($v) => $v->stocks->sum('expected_quantity')) ?? 0;
            $earliestExpDate = $activeVariants
                ->flatMap(fn ($v) => $v->stocks)
                ->whereNotNull('expected_date')
                ->sortBy('expected_date')
                ->first()
                ?->expected_date;

            $badge = ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold);

            // Counter max — first variant's own available stock (safe upper bound for ordering)
            $variantAvailQty = $firstVariant
                ? $firstVariant->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0))
                : 0;
            $variantExpQty = $firstVariant
                ? ($firstVariant->stocks->sum('expected_quantity') ?? 0)
                : 0;

            $maxQty = match ($badge['color']) {
                'success', 'warning' => $variantAvailQty,
                'info'               => $variantExpQty,
                default              => 0,
            };

            $minQty  = max(1, $product->min_order_quantity);
            $step    = max(1, $product->order_step);

            // Initialise the quantity input only if not yet set (preserves user input)
            if ($firstVariant && ! isset($this->quantities[$firstVariant->id])) {
                $this->quantities[$firstVariant->id] = $minQty;
            }

            $price = $firstVariant?->prices->first();

            $productData[$product->id] = [
                'badge'        => $badge,
                'firstVariant' => $firstVariant,
                'price'        => $price,
                'maxQty'       => $maxQty,
                'minQty'       => $minQty,
                'step'         => $step,
            ];
        }

        $categories = Category::orderBy('name')->get();
        $brands = Product::where('is_active', true)
            ->whereNotNull('brand')
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->distinct()->orderBy('brand')->pluck('brand');

        $lightboxProduct = $this->lightboxProductId
            ? Product::find($this->lightboxProductId)
            : null;

        return view('livewire.cabinet.catalog', compact(
            'products',
            'productData',
            'categories',
            'brands',
            'lightboxProduct'
        ));
    }
}
