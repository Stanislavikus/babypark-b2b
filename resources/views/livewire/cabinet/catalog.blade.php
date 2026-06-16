<div>
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

    <div wire:loading.class="opacity-50" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 transition-opacity">
        @forelse($products as $product)
            @php
                $firstVariant = $product->variants->first();
                $price = $firstVariant?->prices->first();
                $totalQty = $product->variants->flatMap->stocks->sum('quantity');
                $threshold = $product->category?->stock_display_threshold ?? 10;
                $images = is_array($product->images) ? $product->images : [];
            @endphp
            <a href="{{ route('cabinet.catalog.show', $product) }}"
               class="group bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col">
                {{-- Photo --}}
                <div class="aspect-square bg-gray-100 flex items-center justify-center overflow-hidden">
                    @if(count($images) > 0)
                        <img src="{{ $images[0] }}"
                             alt="{{ $product->name }}"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div style="display:none" class="w-full h-full items-center justify-center text-gray-300">
                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    @else
                        <div class="flex items-center justify-center text-gray-300">
                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    @endif
                </div>
                {{-- Info --}}
                <div class="p-3 flex flex-col flex-1">
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
                    {{-- Stock badge --}}
                    <span class="mt-1 inline-block text-xs font-medium px-2 py-0.5 rounded-full
                        {{ $totalQty > $threshold ? 'bg-green-100 text-green-800' : ($totalQty > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-500') }}">
                        @if($totalQty > $threshold)
                            В наявності
                        @elseif($totalQty > 0)
                            Залишилось {{ $totalQty }} шт
                        @else
                            Немає в наявності
                        @endif
                    </span>
                </div>
            </a>
        @empty
            <div class="col-span-full py-16 text-center text-gray-400">
                <svg class="mx-auto mb-3 h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                </svg>
                Товари не знайдено
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
