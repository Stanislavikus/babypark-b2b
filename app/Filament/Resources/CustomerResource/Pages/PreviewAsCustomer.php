<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CatalogProductDisplayState;
use App\Enums\UserRole;
use App\Filament\Pages\PriceInspector;
use App\Filament\Resources\CustomerResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\User;
use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerCatalogCriteria;
use App\Support\Workspace\WorkspaceContext;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class PreviewAsCustomer extends Page
{
    use WithPagination;

    public Customer $record;

    protected static string $resource = CustomerResource::class;

    protected static string $view = 'filament.resources.customer-resource.pages.preview-as-customer';

    protected static ?string $title = 'Перегляд як клієнт';

    #[Url]
    public string $search = '';

    /** @var list<string> */
    #[Url]
    public array $selectedCategories = [];

    /** @var list<string> */
    #[Url]
    public array $selectedBrands = [];

    #[Url]
    public string $sortBy = 'sku';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public int $quantity = 1;

    public string $effectiveAtIso;

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && in_array($user->role, [
                UserRole::Admin,
                UserRole::Manager,
                UserRole::Director,
                UserRole::Programmer,
            ], true);
    }

    public function mount(int|string $record): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $this->record = Customer::query()
            ->where('workspace_id', $workspaceId)
            ->findOrFail($record);

        if ($this->quantity < 1) {
            $this->quantity = 1;
        }

        $this->effectiveAtIso = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->refreshSnapshot();
    }

    public function updatedSelectedCategories(): void
    {
        $this->resetPage();
        $this->refreshSnapshot();
    }

    public function updatedSelectedBrands(): void
    {
        $this->resetPage();
        $this->refreshSnapshot();
    }

    public function updatedSortBy(): void
    {
        $this->resetPage();
        $this->refreshSnapshot();
    }

    public function updatedSortDir(): void
    {
        $this->resetPage();
        $this->refreshSnapshot();
    }

    public function updatedQuantity(): void
    {
        if ($this->quantity < 1) {
            $this->quantity = 1;
        }

        $this->resetPage();
        $this->refreshSnapshot();
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
        $this->refreshSnapshot();
    }

    public function resetFilters(): void
    {
        $this->selectedCategories = [];
        $this->selectedBrands = [];
        $this->resetPage();
        $this->refreshSnapshot();
    }

    protected function getViewData(): array
    {
        /** @var Customer $customer */
        $customer = $this->record;
        $effectiveAt = CarbonImmutable::parse($this->effectiveAtIso);
        $snapshot = new PriceResolutionSnapshot($effectiveAt);

        $criteria = CustomerCatalogCriteria::fromLegacy(
            search: $this->search,
            categoryIds: $this->selectedCategories,
            brandIds: $this->selectedBrands,
            sortBy: $this->sortBy,
            sortDir: $this->sortDir,
        );

        $products = app(CustomerCatalogQuery::class)->paginateFor($customer, $criteria);
        $rows = $this->buildRows($products, $customer, $snapshot, $effectiveAt);

        return [
            'customer' => $customer,
            'rows' => $rows,
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'brands' => app(CustomerCatalogQuery::class)->availableBrands($customer),
            'effectiveAt' => $effectiveAt,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRows(
        LengthAwarePaginator $products,
        Customer $customer,
        PriceResolutionSnapshot $snapshot,
        CarbonImmutable $effectiveAt,
    ): array {
        $rows = [];

        foreach ($products as $product) {
            $projection = CatalogRowData::forProduct($product, $customer, $this->quantity, $snapshot);

            $rows[] = [
                'product' => $product,
                'product_id' => $projection->productId,
                'displayed_variant_id' => $projection->displayedVariant?->id,
                'display_state' => $projection->displayState->value,
                'display_state_label' => $this->displayStateLabel($projection->displayState),
                'price' => $projection->price,
                'currency' => $projection->currency,
                'price_source' => $projection->priceSource,
                'orderable' => $projection->orderable,
                'primary_reason' => $projection->primaryReason?->value,
                'price_source_variant_id' => $projection->priceSourceVariant?->id,
                'inspect_url' => $projection->priceSourceVariant !== null
                    ? PriceInspector::getUrl([
                        'customer_id' => $customer->id,
                        'variant_id' => $projection->priceSourceVariant->id,
                        'quantity' => $this->quantity,
                        'effective_at' => $effectiveAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    ])
                    : null,
            ];
        }

        return $rows;
    }

    public function getHeading(): string
    {
        /** @var Customer $customer */
        $customer = $this->record;

        return 'Перегляд як клієнт: '.$customer->name;
    }

    private function refreshSnapshot(): void
    {
        $this->effectiveAtIso = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function displayStateLabel(CatalogProductDisplayState $state): string
    {
        return match ($state) {
            CatalogProductDisplayState::OrderableVariantSelected => 'Доступний для замовлення',
            CatalogProductDisplayState::ExpectedVariantSelected => 'Очікується',
            CatalogProductDisplayState::InformationalPriceOnly => 'Інформаційна ціна',
            CatalogProductDisplayState::ConfigurationError => 'Помилка конфігурації',
            CatalogProductDisplayState::PriceUnavailable => 'Ціна недоступна',
        };
    }
}
