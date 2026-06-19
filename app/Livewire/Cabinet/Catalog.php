<?php

namespace App\Livewire\Cabinet;

use App\Enums\ReservationStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Support\SessionCart;
use Illuminate\Database\Eloquent\Builder;
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

    /** @var list<string> */
    #[Url]
    public array $selectedCategories = [];

    /** @var list<string> */
    #[Url]
    public array $selectedBrands = [];

    /** 'cards' or 'table' */
    #[Url]
    public string $viewMode = 'cards';

    #[Url]
    public string $sortBy = 'sku';

    #[Url]
    public string $sortDir = 'asc';

    /** Margin column display format: 'percent' or 'uah' */
    public string $marginFormat = 'percent';

    /** Quantity inputs keyed by variant_id */
    public array $quantities = [];

    /** Columns hidden by user toggle: 'photo', 'category', 'brand' */
    public array $hiddenColumns = [];

    /** Flash message after cart/reservation action */
    public ?string $flashMessage = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategories(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedBrands(): void
    {
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['cards', 'table']) ? $mode : 'cards';
    }

    public function sortColumn(string $column): void
    {
        $allowed = ['sku', 'name', 'category', 'brand', 'stock', 'price', 'rrp', 'margin'];
        if (! in_array($column, $allowed)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->selectedCategories = [];
        $this->selectedBrands = [];
        $this->resetPage();
    }

    public function removeCategoryFilter(string $categoryId): void
    {
        $this->selectedCategories = array_values(array_diff($this->selectedCategories, [$categoryId]));
        $this->resetPage();
    }

    public function removeBrandFilter(string $brand): void
    {
        $this->selectedBrands = array_values(array_diff($this->selectedBrands, [$brand]));
        $this->resetPage();
    }

    /**
     * Toggle column visibility for optional columns: 'photo', 'category', 'brand'.
     */
    public function toggleColumn(string $column): void
    {
        $allowed = ['photo', 'category', 'brand'];
        if (! in_array($column, $allowed)) {
            return;
        }

        if (in_array($column, $this->hiddenColumns)) {
            $this->hiddenColumns = array_values(array_diff($this->hiddenColumns, [$column]));
        } else {
            $this->hiddenColumns[] = $column;
        }
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
            'variant_id' => $variantId,
            'quantity' => $qty,
            'status' => ReservationStatus::Active,
            'expires_at' => now()->addHours(config('b2b.reservation_ttl_hours', 48)),
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
                'variants.prices' => fn ($q) => $q->where('contractor_id', $contractor->id),
                'variants.stocks',
            ]);

        if (filled($this->search)) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
            );
        }

        if (! empty($this->selectedCategories)) {
            $query->whereIn('category_id', $this->selectedCategories);
        }

        if (! empty($this->selectedBrands)) {
            $query->whereIn('brand', $this->selectedBrands);
        }

        $query = $this->applySorting($query, $contractor);
        $products = $query->paginate(24);

        $productData = [];
        foreach ($products as $product) {
            $threshold = $product->category?->stock_display_threshold ?? 10;
            $activeVariants = $product->variants->where('is_active', true);
            $variantsWithPrice = $activeVariants->filter(fn ($v) => $v->prices->isNotEmpty());
            $firstVariant = $variantsWithPrice->first();

            $allPrices = $variantsWithPrice->flatMap(fn ($v) => $v->prices);
            $maxRrp = (float) ($allPrices->max('recommended_retail_price') ?? 0);
            $maxMyPrice = (float) ($allPrices->max('price_with_vat') ?? 0);

            $totalAvailQty = $activeVariants->sum(fn ($v) => $v->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0)));
            $totalExpQty = $activeVariants->sum(fn ($v) => $v->stocks->sum('expected_quantity')) ?? 0;
            $earliestExpDate = $activeVariants
                ->flatMap(fn ($v) => $v->stocks)
                ->whereNotNull('expected_date')
                ->sortBy('expected_date')
                ->first()
                ?->expected_date;

            $badge = ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold);

            $variantAvailQty = $firstVariant
                ? $firstVariant->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0))
                : 0;
            $variantExpQty = $firstVariant
                ? ($firstVariant->stocks->sum('expected_quantity') ?? 0)
                : 0;

            $maxQty = match ($badge['color']) {
                'success', 'warning' => $variantAvailQty,
                'info' => $variantExpQty,
                default => 0,
            };

            $minQty = max(1, $product->min_order_quantity);
            $step = max(1, $product->order_step);

            if ($firstVariant && ! isset($this->quantities[$firstVariant->id])) {
                $this->quantities[$firstVariant->id] = $minQty;
            }

            $productData[$product->id] = [
                'badge' => $badge,
                'firstVariant' => $firstVariant,
                'maxRrp' => $maxRrp,
                'maxMyPrice' => $maxMyPrice,
                'maxQty' => $maxQty,
                'minQty' => $minQty,
                'step' => $step,
            ];
        }

        $categories = Category::orderBy('name')->get();
        $brands = Product::where('is_active', true)
            ->whereNotNull('brand')
            ->whereHas('variants.prices', fn ($q) => $q->where('contractor_id', $contractor->id))
            ->distinct()->orderBy('brand')->pluck('brand');

        return view('livewire.cabinet.catalog', compact(
            'products',
            'productData',
            'categories',
            'brands',
        ));
    }

    private function applySorting(Builder $query, $contractor): Builder
    {
        $dir = in_array($this->sortDir, ['asc', 'desc']) ? $this->sortDir : 'asc';

        return match ($this->sortBy) {
            'sku' => $query->orderBy('sku', $dir),
            'name' => $query->orderBy('name', $dir),
            'category' => $query->orderBy(
                Category::select('name')
                    ->whereColumn('categories.id', 'products.category_id')
                    ->limit(1),
                $dir
            ),
            'brand' => $query->orderBy('brand', $dir),
            'stock' => $this->applyStockSorting($query, $dir),
            'price' => $query->orderByRaw(
                "COALESCE((SELECT MAX(p.price_with_vat) FROM prices p
                    INNER JOIN product_variants pv ON p.variant_id = pv.id
                    WHERE pv.product_id = products.id
                    AND p.contractor_id = ?), 0) {$dir}",
                [$contractor->id]
            ),
            'rrp' => $query->orderByRaw(
                "COALESCE((SELECT MAX(p.recommended_retail_price) FROM prices p
                    INNER JOIN product_variants pv ON p.variant_id = pv.id
                    WHERE pv.product_id = products.id
                    AND p.contractor_id = ?), 0) {$dir}",
                [$contractor->id]
            ),
            'margin' => $query->orderByRaw(
                "COALESCE((SELECT MAX(p.recommended_retail_price) - MIN(p.price_with_vat) FROM prices p
                    INNER JOIN product_variants pv ON p.variant_id = pv.id
                    WHERE pv.product_id = products.id
                    AND p.contractor_id = ?), 0) {$dir}",
                [$contractor->id]
            ),
            default => $query->orderBy('sku', 'asc'),
        };
    }

    private function applyStockSorting(Builder $query, string $dir): Builder
    {
        $totalQty = '(SELECT COALESCE(SUM(s.quantity), 0)
                      FROM stocks s
                      INNER JOIN product_variants pv ON s.variant_id = pv.id
                      WHERE pv.product_id = products.id)';

        $minExpectedDate = '(SELECT MIN(s.expected_date)
                            FROM stocks s
                            INNER JOIN product_variants pv ON s.variant_id = pv.id
                            WHERE pv.product_id = products.id
                            AND s.expected_date IS NOT NULL)';

        $priorityExpr = "CASE
            WHEN {$totalQty} > 0 THEN 0
            WHEN {$minExpectedDate} IS NOT NULL THEN 1
            ELSE 2
        END";

        return $query
            ->orderByRaw("{$priorityExpr} {$dir}")
            ->orderByRaw("{$totalQty} DESC")
            ->orderByRaw("{$minExpectedDate} ASC");
    }
}
