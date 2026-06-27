<?php

namespace App\Livewire\Cabinet;

use App\Enums\ReservationStatus;
use App\Models\Category;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Services\PriceResolver;
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
                // Load this contractor's contract prices plus the contractor-less types
                // (list_price / cost_of_goods_sold) so PriceResolver works without N+1.
                'variants.productPrices' => fn ($q) => $q->where(
                    fn ($w) => $w->where('contractor_id', $contractor->id)->orWhereNull('contractor_id')
                ),
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

        $resolver = new PriceResolver;

        $productData = [];
        foreach ($products as $product) {
            $threshold = $product->category?->stock_display_threshold ?? 10;

            // The orderable ("Замовити") target is the variant with the lowest contract
            // price; the displayed price, stock badge and order button all derive from this
            // SAME resolved variant, so they can never disagree (the BP-00040 bug class).
            $orderVariant = $resolver->minContractPriceAcrossVariants($product, $contractor);

            $myPriceStr = $orderVariant ? $resolver->contractPrice($orderVariant, $contractor) : null;
            $rrpStr = $resolver->maxListPriceAcrossVariants($product);

            if ($orderVariant) {
                $availQty = $orderVariant->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0));
                $expQty = $orderVariant->stocks->sum('expected_quantity') ?? 0;
                $expDate = $orderVariant->stocks
                    ->whereNotNull('expected_date')
                    ->sortBy('expected_date')
                    ->first()
                    ?->expected_date;
                $badge = ProductVariant::badgeFromQty($availQty, $expQty, $expDate, $threshold);
            } else {
                $availQty = 0;
                $expQty = 0;
                $badge = ['label' => 'Немає в наявності', 'color' => 'danger'];
            }

            $maxQty = match ($badge['color']) {
                'success', 'warning' => $availQty,
                'info' => $expQty,
                default => 0,
            };

            $minQty = max(1, $product->min_order_quantity);
            $step = max(1, $product->order_step);

            if ($orderVariant && ! isset($this->quantities[$orderVariant->id])) {
                $this->quantities[$orderVariant->id] = $minQty;
            }

            $productData[$product->id] = [
                'badge' => $badge,
                'firstVariant' => $orderVariant,
                // Cast to float only at the display boundary; the canonical values are the
                // resolver's decimal strings.
                'maxRrp' => $rrpStr !== null ? (float) $rrpStr : 0.0,
                'maxMyPrice' => $myPriceStr !== null ? (float) $myPriceStr : 0.0,
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

        // Price-type ids are resolved in PHP and injected into the ORDER BY subqueries;
        // DB-level sorting cannot route through PriceResolver, but it reads the same
        // product_prices source so list order matches what the resolver displays.
        $contractTypeId = PriceType::query()->where('code', PriceType::CODE_CONTRACT_PRICE)->value('id');
        $listTypeId = PriceType::query()->where('code', PriceType::CODE_LIST_PRICE)->value('id');

        // Cheapest contract price per product = the value shown for the orderable variant.
        $minContract = '(SELECT MIN(pp.value) FROM product_prices pp
            INNER JOIN product_variants pv ON pp.variant_id = pv.id
            WHERE pv.product_id = products.id AND pp.price_type_id = ? AND pp.contractor_id = ?)';

        $maxList = '(SELECT MAX(pp.value) FROM product_prices pp
            INNER JOIN product_variants pv ON pp.variant_id = pv.id
            WHERE pv.product_id = products.id AND pp.price_type_id = ? AND pp.contractor_id IS NULL)';

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
                "COALESCE({$minContract}, 0) {$dir}",
                [$contractTypeId, $contractor->id]
            ),
            'rrp' => $query->orderByRaw(
                "COALESCE({$maxList}, 0) {$dir}",
                [$listTypeId]
            ),
            'margin' => $query->orderByRaw(
                "COALESCE({$maxList} - {$minContract}, 0) {$dir}",
                [$listTypeId, $contractTypeId, $contractor->id]
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
