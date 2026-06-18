<div>

    {{-- Flash message --}}
    @if($flashMessage)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 2500)"
            x-show="show"
            x-transition:leave="transition ease-in duration-300"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg"
        >
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- ═══════════════════════════════════
         TOOLBAR
    ════════════════════════════════════ --}}
    <div class="mb-4 flex items-end gap-3 flex-wrap">

        {{-- Search --}}
        <div class="flex-1 min-w-48">
            <label class="block text-sm font-medium text-gray-700 mb-1">Пошук</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Назва, артикул, бренд…"
                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm"
            >
        </div>

        {{-- Фільтри button + dropdown panel --}}
        <div x-data="{ open: false }" class="relative">
            <button
                @click="open = !open"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border py-2 px-3 text-sm font-medium shadow-sm transition-colors
                       {{ ($category || $brand) ? 'border-indigo-400 bg-indigo-50 text-indigo-700 ring-2 ring-indigo-200' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z"/>
                </svg>
                Фільтри
                @if($category || $brand)
                    <span class="inline-block w-2 h-2 rounded-full bg-indigo-500"></span>
                @else
                    <svg class="w-3 h-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                    </svg>
                @endif
            </button>

            {{-- Filters panel --}}
            <div
                x-show="open"
                @click.away="open = false"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute top-full left-0 mt-1.5 z-30 w-72 rounded-xl border border-gray-200 bg-white p-4 shadow-lg"
                style="display:none;"
            >
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-900">Фільтри</span>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800 hover:underline"
                    >Скинути</button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Категорії</label>
                        <select
                            wire:model.live="category"
                            class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm"
                        >
                            <option value="">Всі категорії</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Бренди</label>
                        <select
                            wire:model.live="brand"
                            class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm"
                        >
                            <option value="">Всі бренди</option>
                            @foreach($brands as $b)
                                <option value="{{ $b }}">{{ $b }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Стовпці button + dropdown panel (table mode only) --}}
        @if($viewMode === 'table')
            <div x-data="{ open: false }" class="relative">
                <button
                    @click="open = !open"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white py-2 px-3 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                    Стовпці
                    <svg class="w-3 h-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                <div
                    x-show="open"
                    @click.away="open = false"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute top-full left-0 mt-1.5 z-30 w-48 rounded-xl border border-gray-200 bg-white p-4 shadow-lg"
                    style="display:none;"
                >
                    <p class="mb-2 text-sm font-semibold text-gray-900">Стовпці</p>
                    @foreach(['photo' => 'Фото', 'category' => 'Категорія', 'brand' => 'Бренд'] as $colKey => $colLabel)
                        <label class="flex cursor-pointer items-center gap-2 py-1.5 text-sm text-gray-700 hover:text-gray-900">
                            <input
                                type="checkbox"
                                wire:click="toggleColumn('{{ $colKey }}')"
                                @checked(! in_array($colKey, $hiddenColumns))
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                            >
                            {{ $colLabel }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- View toggle: single two-segment control --}}
        <div class="ml-auto inline-flex overflow-hidden rounded-md border border-gray-300" role="group">
            <button
                wire:click="setViewMode('cards')"
                type="button"
                title="Картки"
                class="p-2 transition-colors {{ $viewMode === 'cards' ? 'bg-indigo-100 text-indigo-700' : 'bg-white text-gray-400 hover:bg-gray-50 hover:text-gray-600' }}"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>
                </svg>
            </button>
            <button
                wire:click="setViewMode('table')"
                type="button"
                title="Таблиця"
                class="p-2 border-l border-gray-300 transition-colors {{ $viewMode === 'table' ? 'bg-indigo-100 text-indigo-700' : 'bg-white text-gray-400 hover:bg-gray-50 hover:text-gray-600' }}"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375z"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- ═══════════════════════════════════
         SORT HELPERS (PHP closures in view scope)
    ════════════════════════════════════ --}}
    @php
        $svgUp      = '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>';
        $svgDown    = '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>';
        $svgNeutral = '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"/>';

        $sortPath = function (string $col) use ($sortBy, $sortDir, $svgUp, $svgDown, $svgNeutral): string {
            if ($sortBy !== $col) {
                return $svgNeutral;
            }
            return $sortDir === 'asc' ? $svgUp : $svgDown;
        };

        $sortIconClass = fn (string $col) => $sortBy === $col
            ? 'w-3.5 h-3.5 text-indigo-500'
            : 'w-3.5 h-3.5 text-gray-300 group-hover:text-gray-400';

        $thBase    = 'px-3 py-3 cursor-pointer select-none group';
        $thInner   = 'inline-flex items-center gap-1 hover:text-indigo-700 transition-colors';
        $thInnerR  = 'inline-flex items-center justify-end gap-1 hover:text-indigo-700 transition-colors w-full';

        // Colspan for the empty-results row
        $emptyColspan = 8; // fixed: Артикул, Назва, Наявність, Ваша ціна, РРЦ, Маржа, Кількість, Замовити
        if (! in_array('photo',     $hiddenColumns)) $emptyColspan++;
        if (! in_array('category',  $hiddenColumns)) $emptyColspan++;
        if (! in_array('brand',     $hiddenColumns)) $emptyColspan++;
    @endphp

    {{-- ═══════════════════════════════════
         TABLE VIEW
    ════════════════════════════════════ --}}
    @if($viewMode === 'table')
        <div wire:loading.class="opacity-50" class="transition-opacity overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wide border-b border-gray-200">
                    <tr>

                        {{-- Фото (optional) --}}
                        @if(! in_array('photo', $hiddenColumns))
                            <th class="px-3 py-3 w-14">Фото</th>
                        @endif

                        {{-- Артикул --}}
                        <th wire:click="sortColumn('sku')" class="{{ $thBase }}">
                            <div class="{{ $thInner }}">
                                Артикул
                                <svg class="{{ $sortIconClass('sku') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('sku') !!}
                                </svg>
                            </div>
                        </th>

                        {{-- Назва --}}
                        <th wire:click="sortColumn('name')" class="{{ $thBase }}">
                            <div class="{{ $thInner }}">
                                Назва
                                <svg class="{{ $sortIconClass('name') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('name') !!}
                                </svg>
                            </div>
                        </th>

                        {{-- Категорія (optional) --}}
                        @if(! in_array('category', $hiddenColumns))
                            <th wire:click="sortColumn('category')" class="{{ $thBase }}">
                                <div class="{{ $thInner }}">
                                    Категорія
                                    <svg class="{{ $sortIconClass('category') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        {!! $sortPath('category') !!}
                                    </svg>
                                </div>
                            </th>
                        @endif

                        {{-- Бренд (optional) --}}
                        @if(! in_array('brand', $hiddenColumns))
                            <th wire:click="sortColumn('brand')" class="{{ $thBase }}">
                                <div class="{{ $thInner }}">
                                    Бренд
                                    <svg class="{{ $sortIconClass('brand') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        {!! $sortPath('brand') !!}
                                    </svg>
                                </div>
                            </th>
                        @endif

                        {{-- Наявність --}}
                        <th wire:click="sortColumn('stock')" class="{{ $thBase }} whitespace-nowrap">
                            <div class="{{ $thInner }}">
                                Наявність
                                <svg class="{{ $sortIconClass('stock') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('stock') !!}
                                </svg>
                            </div>
                        </th>

                        {{-- Ваша ціна --}}
                        <th wire:click="sortColumn('price')" class="{{ $thBase }} whitespace-nowrap text-right">
                            <div class="{{ $thInnerR }}">
                                Ваша ціна
                                <svg class="{{ $sortIconClass('price') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('price') !!}
                                </svg>
                            </div>
                        </th>

                        {{-- РРЦ --}}
                        <th wire:click="sortColumn('rrp')" class="{{ $thBase }} whitespace-nowrap text-right">
                            <div class="{{ $thInnerR }}">
                                РРЦ
                                <svg class="{{ $sortIconClass('rrp') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('rrp') !!}
                                </svg>
                            </div>
                        </th>

                        {{-- Маржа — sort on th, format toggle on inner button --}}
                        <th wire:click="sortColumn('margin')" class="{{ $thBase }} whitespace-nowrap text-right">
                            <div class="{{ $thInnerR }}">
                                <button
                                    wire:click.stop="toggleMarginFormat"
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-indigo-700 transition-colors"
                                    title="Перемкнути формат маржі"
                                >
                                    Маржа
                                    <span class="text-[10px] font-bold px-1 py-0.5 rounded bg-gray-200 text-gray-600">
                                        {{ $marginFormat === 'percent' ? '%' : '₴' }}
                                    </span>
                                </button>
                                <svg class="{{ $sortIconClass('margin') }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    {!! $sortPath('margin') !!}
                                </svg>
                            </div>
                        </th>

                        <th class="px-3 py-3 whitespace-nowrap w-36">Кількість</th>
                        <th class="px-3 py-3 whitespace-nowrap w-32">Замовити</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($products as $product)
                        @php
                            $data         = $productData[$product->id];
                            $badge        = $data['badge'];
                            $firstVariant = $data['firstVariant'];
                            $price        = $data['price'];
                            $maxQty       = $data['maxQty'];
                            $minQty       = $data['minQty'];
                            $step         = $data['step'];
                            $images       = is_array($product->images) ? $product->images : [];
                            $photo        = $images[0] ?? null;

                            $rrp       = (float) ($price?->recommended_retail_price ?? 0);
                            $myPrice   = (float) ($price?->price_with_vat ?? 0);
                            $marginUah = $rrp > 0 ? $rrp - $myPrice : null;
                            $marginPct = $rrp > 0 ? (($rrp - $myPrice) / $rrp * 100) : null;

                            $badgeClasses = match($badge['color']) {
                                'success' => 'bg-green-100 text-green-800',
                                'warning' => 'bg-yellow-100 text-yellow-800',
                                'info'    => 'bg-blue-100 text-blue-800',
                                default   => 'bg-gray-100 text-gray-500',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">

                            {{-- Фото (optional) — opens lightbox --}}
                            @if(! in_array('photo', $hiddenColumns))
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        wire:click="openPhotoLightbox({{ $product->id }})"
                                        class="block w-12 h-12 overflow-hidden rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400"
                                        title="Переглянути фото"
                                    >
                                        @if($photo)
                                            <img
                                                src="{{ $photo }}"
                                                alt="{{ $product->name }}"
                                                class="w-full h-full object-cover"
                                                onerror="this.closest('button').innerHTML='<div class=\'w-full h-full bg-gray-100 flex items-center justify-center\'><svg class=\'w-6 h-6 text-gray-300\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'/></svg></div>'"
                                            />
                                        @else
                                            <div class="w-full h-full bg-gray-100 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                        @endif
                                    </button>
                                </td>
                            @endif

                            {{-- Артикул --}}
                            <td class="px-3 py-2 font-mono text-xs text-gray-500 whitespace-nowrap">
                                {{ $product->sku }}
                            </td>

                            {{-- Назва --}}
                            <td class="px-3 py-2 max-w-xs">
                                <a href="{{ route('cabinet.catalog.show', $product) }}"
                                   class="font-medium text-gray-900 hover:text-indigo-700 line-clamp-2">
                                    {{ $product->name }}
                                </a>
                            </td>

                            {{-- Категорія (optional) --}}
                            @if(! in_array('category', $hiddenColumns))
                                <td class="px-3 py-2 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $product->category?->name ?? '—' }}
                                </td>
                            @endif

                            {{-- Бренд (optional) --}}
                            @if(! in_array('brand', $hiddenColumns))
                                <td class="px-3 py-2 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $product->brand ?? '—' }}
                                </td>
                            @endif

                            {{-- Наявність --}}
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                                    {{ $badge['label'] }}
                                </span>
                            </td>

                            {{-- Ваша ціна --}}
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if($price)
                                    <span class="font-semibold text-indigo-700">
                                        {{ number_format($myPrice, 2, ',', ' ') }} ₴
                                    </span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- РРЦ --}}
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if($rrp > 0)
                                    <span class="text-gray-400 line-through">
                                        {{ number_format($rrp, 2, ',', ' ') }} ₴
                                    </span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- Маржа --}}
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if($marginPct !== null)
                                    @if($marginFormat === 'percent')
                                        <span class="font-medium text-emerald-600">{{ number_format($marginPct, 1) }}%</span>
                                    @else
                                        <span class="font-medium text-emerald-600">{{ number_format($marginUah, 2, ',', ' ') }} ₴</span>
                                    @endif
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- Кількість --}}
                            <td class="px-3 py-2">
                                @if($firstVariant && $maxQty > 0)
                                    <div class="flex items-center gap-1">
                                        <button type="button"
                                                wire:click="decrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $minQty }})"
                                                class="w-7 h-7 flex items-center justify-center rounded border border-gray-300 text-gray-600 hover:bg-gray-100 text-sm font-bold leading-none"
                                        >−</button>
                                        <input
                                            type="number"
                                            wire:model.lazy="quantities.{{ $firstVariant->id }}"
                                            min="{{ $minQty }}"
                                            max="{{ $maxQty }}"
                                            step="{{ $step }}"
                                            class="w-16 text-center text-sm border border-gray-300 rounded py-1 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                        <button type="button"
                                                wire:click="incrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $maxQty }})"
                                                class="w-7 h-7 flex items-center justify-center rounded border border-gray-300 text-gray-600 hover:bg-gray-100 text-sm font-bold leading-none"
                                        >+</button>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- Замовити --}}
                            <td class="px-3 py-2">
                                @if($firstVariant && $maxQty > 0)
                                    @if(in_array($badge['color'], ['success', 'warning']))
                                        <button
                                            wire:click="addToCart({{ $firstVariant->id }}, {{ $minQty }})"
                                            class="whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors"
                                        >Купити</button>
                                    @elseif($badge['color'] === 'info')
                                        <button
                                            wire:click="reserve({{ $firstVariant->id }}, {{ $minQty }})"
                                            class="whitespace-nowrap rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors"
                                        >Бронювати</button>
                                    @endif
                                @endif
                            </td>

                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $emptyColspan }}" class="py-16 text-center text-gray-400">Товари не знайдено</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    {{-- ═══════════════════════════════════
         CARDS VIEW
    ════════════════════════════════════ --}}
    @else
        <div wire:loading.class="opacity-50"
             class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 transition-opacity">

            @forelse($products as $product)
                @php
                    $data         = $productData[$product->id];
                    $badge        = $data['badge'];
                    $firstVariant = $data['firstVariant'];
                    $price        = $data['price'];
                    $maxQty       = $data['maxQty'];
                    $minQty       = $data['minQty'];
                    $step         = $data['step'];
                    $images       = is_array($product->images) ? $product->images : [];
                    $photo        = $images[0] ?? null;

                    $rrp     = (float) ($price?->recommended_retail_price ?? 0);
                    $myPrice = (float) ($price?->price_with_vat ?? 0);

                    $badgeClasses = match($badge['color']) {
                        'success' => 'bg-green-100 text-green-800',
                        'warning' => 'bg-yellow-100 text-yellow-800',
                        'info'    => 'bg-blue-100 text-blue-800',
                        default   => 'bg-gray-100 text-gray-500',
                    };
                @endphp

                <div class="group bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col">

                    {{-- Thumbnail — always clickable, opens lightbox --}}
                    <div class="aspect-square bg-gray-100 overflow-hidden relative">
                        <button
                            type="button"
                            wire:click="openPhotoLightbox({{ $product->id }})"
                            class="absolute inset-0 w-full h-full focus:outline-none"
                            title="Переглянути фото"
                        >
                            @if($photo)
                                <img
                                    src="{{ $photo }}"
                                    alt="{{ $product->name }}"
                                    style="width:100%; height:100%; object-fit:cover;"
                                    class="group-hover:scale-105 transition-transform duration-200"
                                    onerror="this.closest('button').innerHTML='<div class=\'flex h-full w-full items-center justify-center text-gray-300\'><svg class=\'w-10 h-10\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'/></svg></div>'"
                                />
                            @else
                                <div class="flex h-full w-full items-center justify-center text-gray-300">
                                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </button>
                    </div>

                    {{-- Card body --}}
                    <div class="p-3 flex flex-col flex-1">
                        <a href="{{ route('cabinet.catalog.show', $product) }}" class="flex flex-col flex-1 hover:opacity-80 transition-opacity">
                            <p class="text-xs text-gray-400 font-mono">{{ $product->sku }}</p>
                            <p class="text-sm font-medium text-gray-900 line-clamp-2 flex-1">{{ $product->name }}</p>
                            @if($product->brand)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $product->brand }}</p>
                            @endif

                            {{-- Prices --}}
                            <div class="mt-2 flex items-baseline gap-2 flex-wrap">
                                @if($rrp > 0)
                                    <span class="text-xs text-gray-400 line-through">{{ number_format($rrp, 2, ',', ' ') }} ₴</span>
                                @endif
                                @if($myPrice > 0)
                                    <span class="text-base font-bold text-green-700">{{ number_format($myPrice, 2, ',', ' ') }} ₴</span>
                                @endif
                            </div>

                            {{-- Badge --}}
                            <span class="mt-1.5 inline-block text-xs font-medium px-2 py-0.5 rounded-full {{ $badgeClasses }}">
                                {{ $badge['label'] }}
                            </span>
                        </a>

                        {{-- Counter + action button --}}
                        @if($firstVariant && $maxQty > 0)
                            <div class="mt-3 flex items-center gap-1.5">
                                <div class="flex items-center gap-0.5 flex-1">
                                    <button type="button"
                                            wire:click="decrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $minQty }})"
                                            class="w-6 h-6 flex items-center justify-center rounded border border-gray-300 text-gray-600 hover:bg-gray-100 text-xs font-bold leading-none"
                                    >−</button>
                                    <input
                                        type="number"
                                        wire:model.lazy="quantities.{{ $firstVariant->id }}"
                                        min="{{ $minQty }}"
                                        max="{{ $maxQty }}"
                                        step="{{ $step }}"
                                        class="w-12 text-center text-xs border border-gray-300 rounded py-0.5 focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                    <button type="button"
                                            wire:click="incrementQty({{ $firstVariant->id }}, {{ $step }}, {{ $maxQty }})"
                                            class="w-6 h-6 flex items-center justify-center rounded border border-gray-300 text-gray-600 hover:bg-gray-100 text-xs font-bold leading-none"
                                    >+</button>
                                </div>
                                @if(in_array($badge['color'], ['success', 'warning']))
                                    <button
                                        wire:click="addToCart({{ $firstVariant->id }}, {{ $minQty }})"
                                        class="rounded-lg bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-700 transition-colors whitespace-nowrap"
                                    >Купити</button>
                                @elseif($badge['color'] === 'info')
                                    <button
                                        wire:click="reserve({{ $firstVariant->id }}, {{ $minQty }})"
                                        class="rounded-lg bg-amber-500 px-2 py-1 text-xs font-medium text-white hover:bg-amber-600 transition-colors whitespace-nowrap"
                                    >Бронювати</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full py-16 text-center text-gray-400">Товари не знайдено</div>
            @endforelse
        </div>
    @endif

    <div class="mt-6">{{ $products->links() }}</div>

    {{-- ═══════════════════════════════════
         PHOTO LIGHTBOX (table + cards modes)
    ════════════════════════════════════ --}}
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
                    class="absolute -top-10 right-0 flex items-center gap-1 text-sm text-white/80 hover:text-white"
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
                    <p class="mt-2 text-center text-sm text-white/80">{{ $lightboxProduct->name }}</p>
                @else
                    <div class="flex h-64 w-64 items-center justify-center rounded-xl bg-gray-800 text-gray-400">
                        Зображення відсутнє
                    </div>
                @endif
            </div>
        </div>
    @endif

</div>
