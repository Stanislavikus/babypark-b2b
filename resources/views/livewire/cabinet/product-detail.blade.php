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

        {{-- ═══════════════════════════════════════
             Photo — images[0] only, max 400px wide
        ════════════════════════════════════════ --}}
        <div>
            @php
                $images = is_array($product->images) ? $product->images : [];
                $photo  = $images[0] ?? null;
            @endphp

            @if($photo)
                {{-- Clickable photo → opens lightbox --}}
                <button
                    type="button"
                    wire:click="openLightbox"
                    class="group block w-full focus:outline-none"
                    title="Збільшити фото"
                >
                    <div class="relative overflow-hidden rounded-xl bg-gray-50 border border-gray-200"
                         style="max-width:400px;">
                        <img
                            src="{{ $photo }}"
                            alt="{{ $product->name }}"
                            style="width:100%; height:auto; max-width:400px; display:block; object-fit:contain; aspect-ratio:1/1; background:#f9fafb;"
                            onerror="this.closest('button').style.display='none'; document.getElementById('photo-placeholder-{{ $product->id }}').style.display='flex';"
                        />
                        <div class="absolute inset-0 flex items-end justify-end p-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="bg-black/50 text-white text-xs px-2 py-1 rounded-full">
                                🔍 Збільшити
                            </span>
                        </div>
                    </div>
                </button>
            @endif

            {{-- Placeholder (shown if no photo OR image fails to load) --}}
            <div
                id="photo-placeholder-{{ $product->id }}"
                style="{{ $photo ? 'display:none;' : '' }} max-width:400px;"
                class="flex items-center justify-center rounded-xl bg-gray-100 border border-gray-200 text-gray-300"
                style="width:100%; aspect-ratio:1/1;"
            >
                <svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
        </div>

        {{-- ═══════════════════════════════════════
             Product info
        ════════════════════════════════════════ --}}
        <div class="flex flex-col gap-5">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $product->name }}</h1>
                <div class="mt-1 flex flex-wrap gap-3 text-sm text-gray-500">
                    <span>Арт:&nbsp;<span class="font-mono font-medium text-gray-700">{{ $product->sku }}</span></span>
                    @if($product->brand)
                        <span>Бренд:&nbsp;<span class="font-medium text-gray-700">{{ $product->brand }}</span></span>
                    @endif
                </div>
            </div>

            {{-- Link to website --}}
            @if($product->product_url)
                <a
                    href="{{ $product->product_url }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 hover:underline"
                >
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
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
                    @if(count($attrs) > 0)
                        <div class="mb-3 flex flex-wrap gap-2">
                            @foreach($attrs as $attr => $val)
                                <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-medium text-gray-600">
                                    {{ $attr }}: {{ $val }}
                                </span>
                            @endforeach
                        </div>
                    @endif

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
                                <p class="text-lg font-medium text-gray-400 line-through">
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

    {{-- ════════════════════════════════════════
         Lightbox overlay — images[0] only, max 600px
    ═════════════════════════════════════════ --}}
    @if($lightboxOpen)
        @php $photo = isset($images[0]) ? $images[0] : null; @endphp
        <div
            wire:click="closeLightbox"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm"
            style="cursor:zoom-out;"
        >
            <div wire:click.stop class="relative mx-4">
                <button
                    wire:click="closeLightbox"
                    class="absolute -top-10 right-0 text-white/80 hover:text-white flex items-center gap-1 text-sm"
                >
                    Закрити
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                @if($photo)
                    <img
                        src="{{ $photo }}"
                        alt="{{ $product->name }}"
                        style="max-width:600px; max-height:85vh; width:100%; height:auto; object-fit:contain; border-radius:10px;"
                        onerror="this.style.display='none'"
                    />
                @endif
            </div>
        </div>
    @endif
</div>
