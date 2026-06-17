<?php

namespace App\Livewire\Cabinet;

use App\Support\SessionCart;
use Livewire\Attributes\On;
use Livewire\Component;

class CartIndicator extends Component
{
    #[On('cart-updated')]
    public function refresh(): void
    {
        // Trigger re-render
    }

    public function render()
    {
        return view('livewire.cabinet.cart-indicator', [
            'count' => SessionCart::count(),
        ]);
    }
}
