@php
    use App\Support\CatalogRowData;

    $contractor = auth('contractor')->user();
    $data = CatalogRowData::forProduct($getRecord(), $contractor);
    $firstVariant = $data['firstVariant'];
    $maxQty = $data['maxQty'];
    $minQty = $data['minQty'];
    $defaultQty = $data['defaultQty'];
    $badge = $data['badge'];
    $myPrice = $data['myPrice'];
@endphp

@if ($firstVariant && $maxQty > 0 && $myPrice !== null)
    @if (in_array($badge['color'], ['success', 'warning'], true))
        <button
            type="button"
            wire:click="addToCart({{ $firstVariant->id }}, {{ $minQty }}, {{ $defaultQty }})"
            class="whitespace-nowrap rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-primary-700"
        >Купити</button>
    @elseif ($badge['color'] === 'info')
        <button
            type="button"
            wire:click="reserve({{ $firstVariant->id }}, {{ $minQty }}, {{ $defaultQty }})"
            class="whitespace-nowrap rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-600"
        >Бронювати</button>
    @endif
@endif
