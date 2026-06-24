@php
    use App\Support\CatalogRowData;

    $contractor = auth('contractor')->user();
    $data = CatalogRowData::forProduct($getRecord(), $contractor);
    $firstVariant = $data['firstVariant'];
    $maxQty = $data['maxQty'];
    $minQty = $data['minQty'];
    $step = $data['step'];
@endphp

@if ($firstVariant && $maxQty > 0)
    <div class="flex items-center gap-1">
        <button
            type="button"
            wire:click="decrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $minQty }})"
            class="flex h-7 w-7 items-center justify-center rounded border border-gray-300 text-sm font-bold leading-none text-gray-600 hover:bg-gray-100"
        >−</button>
        <input
            type="number"
            wire:model.lazy="quantities.{{ $firstVariant->id }}"
            min="{{ $minQty }}"
            max="{{ $maxQty }}"
            step="{{ $step }}"
            class="w-16 rounded border border-gray-300 py-1 text-center text-sm focus:border-primary-500 focus:ring-primary-500"
        >
        <button
            type="button"
            wire:click="incrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $maxQty }})"
            class="flex h-7 w-7 items-center justify-center rounded border border-gray-300 text-sm font-bold leading-none text-gray-600 hover:bg-gray-100"
        >+</button>
    </div>
@else
    <span class="text-xs text-gray-400">—</span>
@endif
