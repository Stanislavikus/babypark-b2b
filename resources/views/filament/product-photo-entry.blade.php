{{--
  Infolist ViewEntry for product photo preview (view page).
  Shows images[0] at max 200×200 with click-to-lightbox via Filament modal.
  $record is injected automatically by Filament's ViewEntry.
--}}
@php
    $images = $record->images ?? [];
    $url    = is_array($images) && count($images) > 0 ? $images[0] : null;
@endphp

@if($url)
    <div>
        <button
            type="button"
            x-data
            x-on:click="$dispatch('open-modal', { id: 'product-photo-{{ $record->id }}' })"
            class="group block focus:outline-none focus:ring-2 focus:ring-primary-500 rounded-lg"
            title="Збільшити"
        >
            <img
                src="{{ $url }}"
                alt="{{ $record->name }}"
                style="max-width:200px; max-height:200px; width:auto; height:auto; border-radius:8px; border:1px solid #e5e7eb; object-fit:contain; background:#f9fafb;"
                class="group-hover:opacity-80 transition-opacity cursor-zoom-in"
                onerror="this.outerHTML='<span class=\'text-sm text-gray-400\'>Не вдалося завантажити</span>'"
            />
            <span class="mt-1 block text-xs text-gray-400 group-hover:text-primary-500">🔍 Збільшити</span>
        </button>

        <x-filament::modal id="product-photo-{{ $record->id }}" width="lg">
            <x-slot name="heading">{{ $record->name }}</x-slot>
            <div class="flex justify-center p-2">
                <img
                    src="{{ $url }}"
                    alt="{{ $record->name }}"
                    style="max-width:600px; max-height:80vh; width:100%; height:auto; object-fit:contain; border-radius:8px; background:#f9fafb;"
                    onerror="this.style.display='none'"
                />
            </div>
        </x-filament::modal>
    </div>
@else
    <span class="text-sm text-gray-400">Зображення відсутнє</span>
@endif
