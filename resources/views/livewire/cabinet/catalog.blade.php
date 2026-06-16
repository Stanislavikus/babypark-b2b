<div>
    {{-- Filters --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Пошук</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Назва, артикул, бренд…"
                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm"
            >
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Категорія</label>
            <select wire:model.live="category"
                    class="block rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm">
                <option value="">Всі категорії</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Бренд</label>
            <select wire:model.live="brand"
                    class="block rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm">
                <option value="">Всі бренди</option>
                @foreach($brands as $b)
                    <option value="{{ $b }}">{{ $b }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Product grid --}}
    <div wire:loading.class="opacity-50"
         class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 transition-opacity">

        @forelse($products as $product)
            @php
                $images     = is_array($product->images) ? $product->images : [];
                $photo      = $images[0] ?? null;                          // images[0] only
                $firstVariant = $product->variants->first();
                $price        = $firstVariant?->prices->first();
                $totalQty     = $product->variants->flatMap->stocks->sum('quantity');
                $threshold    = $product->category?->stock_display_threshold ?? 10;
            @endphp

            <div class="group bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col">

                {{-- Thumbnail — click opens lightbox --}}
                <div class="aspect-square bg-gray-100 overflow-hidden relative">
                    @if($photo)
                        <button
                            type="button"
                            wire:click="openPhotoLightbox({{ $product->id }})"
                            class="absolute inset-0 w-full h-full focus:outline-none"
                            title="Переглянути фото"
                        >
                            <img
                                src="{{ $photo }}"
                                alt="{{ $product->name }}"
                                style="width:100%; height:100%; object-fit:cover;"
                                class="group-hover:scale-105 transition-transform duration-200"
                                onerror="this.closest('button').style.display='none'; this.closest('.aspect-square').querySelector('.photo-placeholder').style.display='flex';"
                            />
                        </button>
                        {{-- Link to product detail covers the rest of the card --}}
                    @endif
                    {{-- Placeholder --}}
                    <div class="photo-placeholder {{ $photo ? 'hidden' : 'flex' }} absolute inset-0 items-center justify-center text-gray-300">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                  d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                </div>

                {{-- Card body — click navigates to product detail --}}
                <a href="{{ route('cabinet.catalog.show', $product) }}"
                   class="p-3 flex flex-col flex-1 hover:bg-gray-50 transition-colors">
                    <p class="text-xs text-gray-400">{{ $product->sku }}</p>
                    <p class="text-sm font-medium text-gray-900 line-clamp-2 flex-1">{{ $product->name }}</p>
                    @if($product->brand)
                        <p class="text-xs text-gray-500 mt-0.5">{{ $product->brand }}</p>
                    @endif
                    @if($price)
                        <p class="mt-1 text-base font-semibold text-indigo-700">
                            {{ number_format($price->price_with_vat, 2, ',', ' ') }} ₴
                        </p>
                    @endif
                    <span class="mt-1 inline-block text-xs font-medium px-2 py-0.5 rounded-full
                        {{ $totalQty > $threshold ? 'bg-green-100 text-green-800' : ($totalQty > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-500') }}">
                        @if($totalQty > $threshold) В наявності
                        @elseif($totalQty > 0) Залишилось {{ $totalQty }} шт
                        @else Немає в наявності
                        @endif
                    </span>
                </a>
            </div>
        @empty
            <div class="col-span-full py-16 text-center text-gray-400">
                Товари не знайдено
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $products->links() }}</div>

    {{-- ════════════════════════════════════════════════════════
         Catalog photo lightbox — images[0] only, max 600px wide
    ═════════════════════════════════════════════════════════ --}}
    @if($lightboxProduct)
        @php
            $lbImages = is_array($lightboxProduct->images) ? $lightboxProduct->images : [];
            $lbPhoto  = $lbImages[0] ?? null;
        @endphp
        <div
            wire:click="closePhotoLightbox"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm"
            style="cursor:zoom-out;"
        >
            <div wire:click.stop class="relative mx-4">
                <button
                    wire:click="closePhotoLightbox"
                    class="absolute -top-10 right-0 text-white/80 hover:text-white flex items-center gap-1 text-sm"
                >
                    Закрити
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                @if($lbPhoto)
                    <img
                        src="{{ $lbPhoto }}"
                        alt="{{ $lightboxProduct->name }}"
                        style="max-width:600px; max-height:85vh; width:100%; height:auto; object-fit:contain; border-radius:10px;"
                        onerror="this.style.display='none'"
                    />
                    <p class="mt-2 text-center text-white/80 text-sm">{{ $lightboxProduct->name }}</p>
                @else
                    <div class="flex items-center justify-center w-64 h-64 rounded-xl bg-gray-800 text-gray-400">
                        Зображення відсутнє
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
