<?php

namespace App\Livewire\Cabinet;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Availability\ReservationCreator;
use App\Services\Pricing\PricingSqlExpressions;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerPricingScope;
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
        $customer = Auth::guard('customer')->user();
        $qty = max($minQty, (int) ($this->quantities[$variantId] ?? $minQty));

        $variant = ProductVariant::query()->findOrFail($variantId);

        app(ReservationCreator::class)->create(
            $variant,
            $qty,
            customer: $customer,
            ttlMinutes: config('b2b.reservation_ttl_hours', 48) * 60,
        );

        $this->flashMessage = 'Бронювання створено';
        $this->dispatch('flash', message: $this->flashMessage);
    }

    public function render()
    {
        $customer = Auth::guard('customer')->user();

        $query = CustomerPricingScope::applyProductScope(
            Product::query()->where('is_active', true),
            $customer,
        )->with(CustomerPricingScope::eagerLoadForCustomer($customer));

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

        $query = $this->applySorting($query, $customer);
        $products = $query->paginate(24);

        $productData = [];
        foreach ($products as $product) {
            $data = CatalogRowData::forProduct($product, $customer);
            $firstVariant = $data['firstVariant'];

            if ($firstVariant && ! isset($this->quantities[$firstVariant->id])) {
                $this->quantities[$firstVariant->id] = $data['minQty'];
            }

            $productData[$product->id] = [
                'badge' => $data['badge'],
                'firstVariant' => $firstVariant,
                'maxRrp' => (float) ($data['rrp'] ?? 0),
                'maxMyPrice' => (float) ($data['myPrice'] ?? 0),
                'maxQty' => $data['maxQty'],
                'minQty' => $data['minQty'],
                'step' => $data['step'],
            ];
        }

        $categories = Category::orderBy('name')->get();
        $brands = CustomerPricingScope::applyProductScope(
            Product::query()->where('is_active', true)->whereNotNull('brand'),
            $customer,
        )->distinct()->orderBy('brand')->pluck('brand');

        return view('livewire.cabinet.catalog', compact(
            'products',
            'productData',
            'categories',
            'brands',
        ));
    }

    private function applySorting(Builder $query, $customer): Builder
    {
        $dir = in_array($this->sortDir, ['asc', 'desc']) ? $this->sortDir : 'asc';
        $priceListId = CustomerPricingScope::priceListIdFor($customer);

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
            'price' => $priceListId
                ? $query->orderByRaw(
                    'COALESCE('.PricingSqlExpressions::maxGrossPriceSqlForProduct('products.id', $priceListId).", 0) {$dir}"
                )
                : $query->orderBy('sku', $dir),
            'rrp' => $query->orderByRaw(
                'COALESCE('.PricingSqlExpressions::maxRrpSqlForProduct('products.id').", 0) {$dir}"
            ),
            'margin' => $priceListId
                ? $query->orderByRaw(
                    PricingSqlExpressions::customerMarginSortSql('products.id', $priceListId)." {$dir}"
                )
                : $query->orderBy('sku', $dir),
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
