@php
    use App\Support\CatalogRowData;
    use Livewire\Livewire;

    $contractor = auth('contractor')->user();
    $data = CatalogRowData::forProduct($getRecord(), $contractor);
    $firstVariant = $data['firstVariant'];
    $maxQty = $data['maxQty'];
    $minQty = $data['minQty'];
    $step = $data['step'];
    $badge = $data['badge'];
    $myPrice = $data['myPrice'];

    $livewire = Livewire::current();
    $currentQty = (int) ($livewire?->quantities[$firstVariant?->id] ?? 0);
    $qtyMuted = $currentQty === 0;
@endphp

@if ($firstVariant && $maxQty > 0 && $myPrice !== null)
    <div class="flex items-center gap-3">
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="decrementQty({{ $firstVariant->id }}, {{ $step }})"
                class="flex h-7 w-7 items-center justify-center rounded border border-gray-300 text-sm font-bold leading-none text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-white/10"
            >−</button>
            <input
                type="number"
                wire:model.lazy="quantities.{{ $firstVariant->id }}"
                min="0"
                max="{{ $maxQty }}"
                step="{{ $step }}"
                class="w-16 rounded border border-gray-300 py-1 text-center text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-transparent {{ $qtyMuted ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-gray-100' }}"
            >
            <button
                type="button"
                wire:click="incrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $maxQty }})"
                class="flex h-7 w-7 items-center justify-center rounded border border-gray-300 text-sm font-bold leading-none text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-white/10"
            >+</button>
        </div>

        @if (in_array($badge['color'], ['success', 'warning'], true))
            <x-filament::button
                :color="$badge['color']"
                size="xs"
                wire:click="addToCart({{ $firstVariant->id }}, {{ $minQty }})"
            >
                Купити
            </x-filament::button>
        @elseif ($badge['color'] === 'info')
            <x-filament::button
                :color="$badge['color']"
                size="xs"
                wire:click="reserve({{ $firstVariant->id }}, {{ $minQty }})"
            >
                Бронювати
            </x-filament::button>
        @endif
    </div>
@else
    <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
@endif
