<?php

namespace App\Filament\Cabinet\Resources\ProductResource\Pages;

use App\Enums\ReservationStatus;
use App\Filament\Cabinet\Resources\ProductResource;
use App\Models\Reservation;
use App\Support\SessionCart;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /** Margin column display format: 'percent' or 'uah' */
    public string $marginFormat = 'percent';

    /** Quantity inputs keyed by variant_id */
    public array $quantities = [];

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function toggleMarginFormat(): void
    {
        $this->marginFormat = $this->marginFormat === 'percent' ? 'uah' : 'percent';
    }

    public function incrementQty(int $variantId, int $step, int $maxQty): void
    {
        $current = (int) ($this->quantities[$variantId] ?? 0);
        $this->quantities[$variantId] = min($maxQty, $current + $step);
    }

    public function decrementQty(int $variantId, int $step): void
    {
        $current = (int) ($this->quantities[$variantId] ?? 0);
        $this->quantities[$variantId] = max(0, $current - $step);
    }

    public function addToCart(int $variantId, int $minQty): void
    {
        $qty = max($minQty, (int) ($this->quantities[$variantId] ?? 0));
        SessionCart::add($variantId, $qty);

        Notification::make()
            ->title('Додано до кошика')
            ->success()
            ->send();

        $this->dispatch('cart-updated');
    }

    public function reserve(int $variantId, int $minQty): void
    {
        $contractor = auth('contractor')->user();
        $qty = max($minQty, (int) ($this->quantities[$variantId] ?? 0));

        Reservation::create([
            'contractor_id' => $contractor->id,
            'variant_id' => $variantId,
            'quantity' => $qty,
            'status' => ReservationStatus::Active,
            'expires_at' => now()->addHours(config('b2b.reservation_ttl_hours', 48)),
        ]);

        Notification::make()
            ->title('Бронювання створено')
            ->success()
            ->send();
    }

    #[On('cart-updated')]
    public function refreshCartBadge(): void
    {
        // Trigger re-render for navigation badge refresh on next navigation.
    }
}
