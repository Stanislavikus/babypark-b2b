<?php

namespace App\Livewire\Cabinet;

use App\Enums\CatalogProductDisplayState;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Services\Availability\ReservationCreator;
use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerCatalogCriteria;
use App\Support\Pricing\CustomerFacingPriceLabel;
use App\Support\SessionCart;
use Carbon\CarbonImmutable;
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
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        $criteria = CustomerCatalogCriteria::fromLegacy(
            search: $this->search,
            categoryIds: $this->selectedCategories,
            brandIds: $this->selectedBrands,
            sortBy: $this->sortBy,
            sortDir: $this->sortDir,
        );

        $catalogQuery = app(CustomerCatalogQuery::class);
        $products = $catalogQuery->paginateFor($customer, $criteria);

        $productData = [];
        foreach ($products as $product) {
            $row = CatalogRowData::forProduct($product, $customer, 1, $snapshot);
            $firstVariant = $row->displayedVariant;

            if ($firstVariant && ! isset($this->quantities[$firstVariant->id])) {
                $this->quantities[$firstVariant->id] = $row->minQty;
            }

            $productData[$product->id] = [
                'badge' => $row->badge,
                'firstVariant' => $firstVariant,
                'maxRrp' => (float) ($row->rrp ?? 0),
                'maxMyPrice' => (float) ($row->price ?? 0),
                'priceLabel' => $row->myPriceDisplay !== null
                    ? CustomerFacingPriceLabel::forDisplay($row->myPriceDisplay)
                    : ($row->displayState === CatalogProductDisplayState::ConfigurationError
                        ? 'Помилка конфігурації цін'
                        : ($row->displayState === CatalogProductDisplayState::PriceUnavailable
                            ? 'Ціна недоступна'
                            : null)),
                'maxQty' => $row->maxQty,
                'minQty' => $row->minQty,
                'step' => $row->step,
                'displayState' => $row->displayState,
            ];
        }

        $categories = Category::orderBy('name')->get();
        $brands = $catalogQuery->availableBrands($customer);

        return view('livewire.cabinet.catalog', compact(
            'products',
            'productData',
            'categories',
            'brands',
        ));
    }
}
