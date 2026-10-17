<?php

namespace App\Livewire\Cabinet;

use App\Support\SessionCart;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Self-contained cart summary for the catalog toolbar.
 * Designed as a sibling-friendly widget — a future credit-limit indicator
 * can sit next to this without restructuring the layout.
 */
class CartToolbar extends Component
{
    #[On('cart-updated')]
    public function refresh(): void
    {
        // Trigger re-render
    }

    public function render()
    {
        $customer = auth('customer')->user();
        $lines = SessionCart::linesForCustomer($customer);

        return view('livewire.cabinet.cart-toolbar', [
            'lines' => $lines,
            'count' => count($lines),
            'total' => SessionCart::totalWithVat($customer),
        ]);
    }
}
