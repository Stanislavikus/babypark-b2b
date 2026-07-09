<div>
    {{-- Breadcrumb --}}
    <nav class="mb-4 flex items-center gap-2 text-sm text-gray-500">
        <a href="{{ route('cabinet.catalog') }}" class="hover:text-primary-600">Каталог</a>
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
                @php
                    $lbUrl = addslashes($photo);
                    $lbTitle = addslashes($product->name);
                @endphp
                <div class="relative overflow-hidden rounded-xl border border-gray-200" style="max-width:400px;">
                    <img
                        src="{{ $photo }}"
                        alt="{{ $product->name }}"
                        style="width:100%; height:auto; max-width:400px; display:block; object-fit:contain; aspect-ratio:1/1; background:#f9fafb; cursor:zoom-in;"
                        title="Натисніть для збільшення"
                        onclick="event.stopPropagation();event.preventDefault();bpOpenLightbox('{{ $lbUrl }}','{{ $lbTitle }}')"
                        onerror="this.style.display='none'; document.getElementById('photo-placeholder-{{ $product->id }}').style.display='flex';"
                    />
                </div>
            @endif

            {{-- Placeholder (shown if no photo OR image fails to load) --}}
            <div
                id="photo-placeholder-{{ $product->id }}"
                class="flex items-center justify-center rounded-xl border border-gray-200"
                style="{{ $photo ? 'display:none;' : '' }} width:100%; max-width:400px; aspect-ratio:1/1; background:#f3f4f6; cursor:default;"
            >
                <svg class="w-16 h-16" style="color:#d1d5db;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
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
                    class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700 hover:underline"
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
                            <p class="text-3xl font-bold text-primary-700">
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

                    {{-- Stock per location --}}
                    @if(isset($variantStockDisplay[$variant->id]) && count($variantStockDisplay[$variant->id]) > 0)
                        <div class="mt-3 space-y-1.5">
                            @foreach($variantStockDisplay[$variant->id] as $stockRow)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-600">{{ $stockRow['location_name'] }}</span>
                                    <span class="{{ $stockRow['status_class'] }}">{{ $stockRow['status_label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
