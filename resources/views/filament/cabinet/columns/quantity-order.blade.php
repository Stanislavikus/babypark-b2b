@php
    use App\Support\CatalogRowData;
    use Livewire\Livewire;

    $customer = auth('customer')->user();
    $row = CatalogRowData::forProduct($getRecord(), $customer);
    $firstVariant = $row->displayedVariant;
    $maxQty = $row->maxQty;
    $minQty = $row->minQty;
    $step = $row->step;
    $badge = $row->badge;
    $myPrice = $row->price;

    $livewire = Livewire::current();
    $currentQty = (int) ($livewire?->quantities[$firstVariant?->id] ?? 0);
    $qtyMuted = $currentQty === 0;
@endphp

@if ($firstVariant && $maxQty > 0 && $myPrice !== null)
    <div class="flex items-center gap-3" onclick="event.stopPropagation();">
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click.stop="decrementQty({{ $firstVariant->id }}, {{ $step }})"
                class="flex h-7 w-7 items-center justify-center rounded border bp-muted-border bp-muted-control text-sm font-bold leading-none hover:bg-gray-100 dark:hover:bg-white/10"
            >−</button>
            <input
                type="number"
                wire:model.lazy="quantities.{{ $firstVariant->id }}"
                wire:click.stop
                onclick="event.stopPropagation()"
                min="0"
                max="{{ $maxQty }}"
                step="{{ $step }}"
                class="w-16 rounded border bp-muted-border py-1 text-center text-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-transparent {{ $qtyMuted ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-gray-100' }}"
            >
            <button
                type="button"
                wire:click.stop="incrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $maxQty }})"
                class="flex h-7 w-7 items-center justify-center rounded border bp-muted-border bp-muted-control text-sm font-bold leading-none hover:bg-gray-100 dark:hover:bg-white/10"
            >+</button>
        </div>

        @if (in_array($badge['color'], ['success', 'warning'], true))
            <x-filament::button
                :color="$badge['color']"
                size="xs"
                wire:click.stop="addToCart({{ $firstVariant->id }}, {{ $minQty }})"
            >
                Купити
            </x-filament::button>
        @elseif ($badge['color'] === 'info')
            <x-filament::button
                :color="$badge['color']"
                size="xs"
                wire:click.stop="reserve({{ $firstVariant->id }}, {{ $minQty }})"
            >
                Бронювати
            </x-filament::button>
        @endif
    </div>
@else
    <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
@endif
