<?php

namespace App\Livewire\Cabinet;

use App\Models\Product;
use App\Services\Availability\AvailabilityResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class ProductDetail extends Component
{
    public Product $product;

    /** @var array<int, array<int, array{location_name: string, status_label: string, status_class: string}>> */
    public array $variantStockDisplay = [];

    /** @var array<int, int> */
    public array $variantNetAvailability = [];

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
            'variants.stocks.inventoryLocation',
            'variants.prices' => fn ($q) => $q->where('contractor_id', $contractor->id),
        ]);

        $threshold = $product->category?->stock_display_threshold ?? 10;
        $resolver = app(AvailabilityResolver::class);

        foreach ($this->product->variants->where('is_active', true) as $variant) {
            if ($variant->prices->isEmpty()) {
                continue;
            }

            $rows = [];

            foreach ($variant->stocks as $stock) {
                $qty = (int) $stock->quantity;

                if ($qty > $threshold) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'В наявності',
                        'status_class' => 'font-medium text-green-700',
                    ];
                } elseif ($qty > 0) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => "Залишилось {$qty} шт",
                        'status_class' => 'font-medium text-yellow-700',
                    ];
                } elseif ($stock->expected_date) {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'Очікується '.$stock->expected_date->format('d.m'),
                        'status_class' => 'text-blue-700',
                    ];
                } else {
                    $rows[] = [
                        'location_name' => $stock->inventoryLocation?->name ?? '—',
                        'status_label' => 'Немає в наявності',
                        'status_class' => 'text-gray-400',
                    ];
                }
            }

            $this->variantStockDisplay[$variant->id] = $rows;
            $this->variantNetAvailability[$variant->id] = $resolver->netAvailable($variant);
        }
    }

    public function render()
    {
        return view('livewire.cabinet.product-detail', [
            'contractor' => Auth::guard('contractor')->user(),
        ]);
    }
}
