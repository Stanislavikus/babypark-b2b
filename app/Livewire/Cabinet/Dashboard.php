<?php

namespace App\Livewire\Cabinet;

use App\Models\Order;
use App\Support\SessionCart;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class Dashboard extends Component
{
    public ?string $flashMessage = null;

    /**
     * Copy all items from a past order into the session cart.
     * Does NOT create a new order — only fills the cart for review.
     */
    public function repeatOrder(int $orderId): void
    {
        $contractor = Auth::guard('contractor')->user();

        $order = Order::where('contractor_id', $contractor->id)->find($orderId);

        if (! $order) {
            return;
        }

        SessionCart::addFromOrder($order);

        $this->flashMessage = 'Позиції замовлення додано до кошика';
        $this->dispatch('cart-updated');
    }

    public function render()
    {
        $contractor = Auth::guard('contractor')->user();

        $contractor->loadMissing(['accountManager', 'backupManager']);

        $recentOrders = Order::where('contractor_id', $contractor->id)
            ->with('items')
            ->latest()
            ->take(10)
            ->get();

        return view('livewire.cabinet.dashboard', [
            'contractor'   => $contractor,
            'manager'      => $contractor->effectiveManager(),
            'recentOrders' => $recentOrders,
        ]);
    }
}
