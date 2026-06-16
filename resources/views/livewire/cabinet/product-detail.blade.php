<div>
    {{-- Breadcrumb --}}
    <nav class="mb-4 flex items-center gap-2 text-sm text-gray-500">
        <a href="{{ route('cabinet.catalog') }}" class="hover:text-indigo-600">Каталог</a>
        <span>/</span>
        @if($product->category)
            <span>{{ $product->category->name }}</span>
            <span>/</span>
        @endif
        <span class="text-gray-900 font-medium">{{ $product->name }}</span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Photo gallery --}}
        <div>
            @php $images = is_array($product->images) ? $product->images : []; @endphp

            @if(count($images) > 0)
                {{-- Main photo --}}
                <div
                    class="relative aspect-square overflow-hidden rounded-xl bg-gray-100 cursor-zoom-in border border-gray-200"
                    wire:click="openLightbox(0)"
                >
                    <img src="{{ $images[0] }}"
                         alt="{{ $product->name }}"
                         class="w-full h-full object-contain"
                         onerror="this.style.display='none'">
                    <div class="absolute bottom-2 right-2 bg-black/40 text-white text-xs px-2 py-1 rounded-full">
                        🔍 Збільшити
                    </div>
                </div>

                {{-- Thumbnails --}}
                @if(count($images) > 1)
                    <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        @foreach($images as $i => $url)
                            <button
                                wire:click="openLightbox({{ $i }})"
                                class="flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden border-2 border-gray-200 hover:border-indigo-400 transition-colors"
                            >
                                <img src="{{ $url }}" alt="" class="w-full h-full object-cover"
                                     onerror="this.style.display='none'">
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="aspect-square rounded-xl bg-gray-100 flex items-center justify-center text-gray-300 border border-gray-200">
                    <svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
            @endif
        </div>

        {{-- Product info --}}
        <div class="flex flex-col gap-5">
            <div>
                <div class="flex items-start justify-between gap-4">
                    <h1 class="text-2xl font-bold text-gray-900">{{ $product->name }}</h1>
                </div>
                <div class="mt-1 flex flex-wrap gap-3 text-sm text-gray-500">
                    <span>Арт: <span class="font-mono font-medium text-gray-700">{{ $product->sku }}</span></span>
                    @if($product->brand)
                        <span>Бренд: <span class="font-medium text-gray-700">{{ $product->brand }}</span></span>
                    @endif
                </div>
            </div>

            {{-- Link to product website --}}
            @if($product->product_url)
                <a href="{{ $product->product_url }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 hover:underline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                    </svg>
                    Переглянути на сайті
                </a>
            @endif

            {{-- Variants with prices --}}
            @foreach($product->variants->where('is_active', true) as $variant)
                @php
                    $price = $variant->prices->first();
                    if (! $price) continue;
                    $attrs = is_array($variant->attributes) ? $variant->attributes : [];
                @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    {{-- Variant attributes --}}
                    @if(count($attrs) > 0)
                        <div class="mb-3 flex flex-wrap gap-2">
                            @foreach($attrs as $attr => $val)
                                <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-medium text-gray-600">
                                    {{ $attr }}: {{ $val }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Price block --}}
                    <div class="flex items-end gap-4 flex-wrap">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Ваша ціна (з ПДВ)</p>
                            <p class="text-3xl font-bold text-indigo-700">
                                {{ number_format($price->price_with_vat, 2, ',', ' ') }} ₴
                            </p>
                        </div>
                        @if($price->recommended_retail_price)
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Рекомендована роздрібна</p>
                                <p class="text-lg font-medium text-gray-500 line-through">
                                    {{ number_format($price->recommended_retail_price, 2, ',', ' ') }} ₴
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Stock per warehouse --}}
                    @if($variant->stocks->count() > 0)
                        <div class="mt-3 space-y-1.5">
                            @foreach($variant->stocks as $stock)
                                @php
                                    $threshold = $product->category?->stock_display_threshold ?? 10;
                                    $qty = $stock->quantity - ($stock->reserved ?? 0);
                                @endphp
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-600">{{ $stock->warehouse_name }}</span>
                                    @if($qty > $threshold)
                                        <span class="font-medium text-green-700">В наявності</span>
                                    @elseif($qty > 0)
                                        <span class="font-medium text-yellow-700">Залишилось {{ $qty }} шт</span>
                                    @elseif($stock->expected_date)
                                        <span class="text-blue-700">Очікується {{ $stock->expected_date->format('d.m') }}</span>
                                    @else
                                        <span class="text-gray-400">Немає в наявності</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Lightbox --}}
    @if($lightboxIndex !== null)
        @php $images = is_array($product->images) ? $product->images : []; @endphp
        <div
            wire:click="closeLightbox"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm"
        >
            <div class="relative max-w-3xl max-h-[90vh] w-full mx-4" wire:click.stop>
                <button
                    wire:click="closeLightbox"
                    class="absolute -top-10 right-0 text-white/80 hover:text-white text-sm flex items-center gap-1"
                >
                    <span>Закрити</span>
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                <img
                    src="{{ $images[$lightboxIndex] ?? '' }}"
                    alt="{{ $product->name }}"
                    class="w-full max-h-[85vh] object-contain rounded-lg"
                >
                @if(count($images) > 1)
                    <div class="mt-3 flex gap-2 justify-center overflow-x-auto">
                        @foreach($images as $i => $url)
                            <button wire:click="openLightbox({{ $i }})"
                                    class="w-12 h-12 rounded overflow-hidden border-2 transition-colors flex-shrink-0
                                           {{ $i === $lightboxIndex ? 'border-white' : 'border-white/30 hover:border-white/60' }}">
                                <img src="{{ $url }}" alt="" class="w-full h-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
