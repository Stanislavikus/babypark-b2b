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
            <x-heroicon-m-check class="w-4 h-4 shrink-0" />
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
                <x-heroicon-m-funnel class="w-4 h-4" />
                Фільтри
                @if($category || $brand)
                    <span class="inline-block w-2 h-2 rounded-full bg-indigo-500"></span>
                @else
                    <x-heroicon-m-chevron-down class="w-3 h-3 text-gray-400" />
                @endif
            </button>

            {{-- Filters panel — matches admin fi-ta-filters structure --}}
            <div
                x-show="open"
                @click.away="open = false"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute top-full left-0 mt-1.5 z-30 w-72 rounded-xl border border-gray-200 bg-white p-6 shadow-lg"
                style="display:none;"
            >
                {{-- Header row: Фільтри + Скинути — matches fi-ta-filters heading row --}}
                <div class="mb-4 flex items-center justify-between">
                    <h4 class="text-base font-semibold leading-6 text-gray-950">Фільтри</h4>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-sm font-medium text-red-600 hover:text-red-500"
                    >Скинути</button>
                </div>

                <div class="grid gap-y-4">
                    <div>
                        <label class="block text-sm font-medium leading-6 text-gray-950 mb-1">Категорії</label>
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
                        <label class="block text-sm font-medium leading-6 text-gray-950 mb-1">Бренди</label>
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
                    <x-heroicon-m-view-columns class="w-4 h-4" />
                    Стовпці
                    <x-heroicon-m-chevron-down class="w-3 h-3 text-gray-400" />
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
                    <p class="mb-2 text-sm font-semibold text-gray-950">Стовпці</p>
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
                <x-heroicon-m-squares-2x2 class="w-5 h-5" />
            </button>
            <button
                wire:click="setViewMode('table')"
                type="button"
                title="Таблиця"
                class="p-2 border-l border-gray-300 transition-colors {{ $viewMode === 'table' ? 'bg-indigo-100 text-indigo-700' : 'bg-white text-gray-400 hover:bg-gray-50 hover:text-gray-600' }}"
            >
                <x-heroicon-m-table-cells class="w-5 h-5" />
            </button>
        </div>
    </div>

    {{-- ═══════════════════════════════════
         SORT HELPERS — match admin's header-cell.blade.php exactly:
         • unsorted  → heroicon-m-chevron-down, text-gray-400 group-hover:text-gray-500
         • sorted ASC → heroicon-m-chevron-up,   text-gray-950
         • sorted DESC → heroicon-m-chevron-down, text-gray-950
    ════════════════════════════════════ --}}
    @php
        $sortIconComponent = fn (string $col): string =>
            ($sortBy === $col && $sortDir === 'asc') ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down';

        $sortIconClass = fn (string $col) => $sortBy === $col
            ? 'h-5 w-5 shrink-0 transition duration-75 text-gray-950'
            : 'h-5 w-5 shrink-0 transition duration-75 text-gray-400 group-hover:text-gray-500';

        // th: matches fi-ta-header-cell px-3 py-3.5; group enables group-hover on icon
        $thBase   = 'px-3 py-3.5 cursor-pointer select-none group';
        // inner span: matches admin's "group flex w-full items-center gap-x-1 cursor-pointer"
        $thInner  = 'flex w-full items-center gap-x-1';
        $thInnerR = 'flex w-full items-center justify-end gap-x-1';

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
        {{-- Outer wrapper: ring-1 ring-gray-950/5 matches fi-ta-ctn --}}
        <div wire:loading.class="opacity-50" class="transition-opacity overflow-x-auto rounded-xl shadow-sm ring-1 ring-gray-950/5">
            {{-- Table: divide-y divide-gray-200 matches fi-ta-table --}}
            <table class="w-full table-auto divide-y divide-gray-200 text-start">
                {{-- thead: bg-gray-50 matches admin; NO uppercase/tracking-wide --}}
                <thead class="bg-gray-50">
                    <tr>

                        {{-- Фото (optional, non-sortable) --}}
                        @if(! in_array('photo', $hiddenColumns))
                            <th class="px-3 py-3.5 w-14">
                                <span class="text-sm font-semibold text-gray-950">Фото</span>
                            </th>
                        @endif

                        {{-- Артикул --}}
                        <th wire:click="sortColumn('sku')" class="{{ $thBase }}">
                            <div class="{{ $thInner }}">
                                <span class="text-sm font-semibold text-gray-950">Артикул</span>
                                <x-dynamic-component :component="$sortIconComponent('sku')" :class="$sortIconClass('sku')" />
                            </div>
                        </th>

                        {{-- Назва --}}
                        <th wire:click="sortColumn('name')" class="{{ $thBase }}">
                            <div class="{{ $thInner }}">
                                <span class="text-sm font-semibold text-gray-950">Назва</span>
                                <x-dynamic-component :component="$sortIconComponent('name')" :class="$sortIconClass('name')" />
                            </div>
                        </th>

                        {{-- Категорія (optional) --}}
                        @if(! in_array('category', $hiddenColumns))
                            <th wire:click="sortColumn('category')" class="{{ $thBase }}">
                                <div class="{{ $thInner }}">
                                    <span class="text-sm font-semibold text-gray-950">Категорія</span>
                                    <x-dynamic-component :component="$sortIconComponent('category')" :class="$sortIconClass('category')" />
                                </div>
                            </th>
                        @endif

                        {{-- Бренд (optional) --}}
                        @if(! in_array('brand', $hiddenColumns))
                            <th wire:click="sortColumn('brand')" class="{{ $thBase }}">
                                <div class="{{ $thInner }}">
                                    <span class="text-sm font-semibold text-gray-950">Бренд</span>
                                    <x-dynamic-component :component="$sortIconComponent('brand')" :class="$sortIconClass('brand')" />
                                </div>
                            </th>
                        @endif

                        {{-- Наявність --}}
                        <th wire:click="sortColumn('stock')" class="{{ $thBase }} whitespace-nowrap">
                            <div class="{{ $thInner }}">
                                <span class="text-sm font-semibold text-gray-950">Наявність</span>
                                <x-dynamic-component :component="$sortIconComponent('stock')" :class="$sortIconClass('stock')" />
                            </div>
                        </th>

                        {{-- Ваша ціна --}}
                        <th wire:click="sortColumn('price')" class="{{ $thBase }} whitespace-nowrap">
                            <div class="{{ $thInnerR }}">
                                <span class="text-sm font-semibold text-gray-950">Ваша ціна</span>
                                <x-dynamic-component :component="$sortIconComponent('price')" :class="$sortIconClass('price')" />
                            </div>
                        </th>

                        {{-- РРЦ --}}
                        <th wire:click="sortColumn('rrp')" class="{{ $thBase }} whitespace-nowrap">
                            <div class="{{ $thInnerR }}">
                                <span class="text-sm font-semibold text-gray-950">РРЦ</span>
                                <x-dynamic-component :component="$sortIconComponent('rrp')" :class="$sortIconClass('rrp')" />
                            </div>
                        </th>

                        {{-- Маржа — sort on th, format toggle on inner button --}}
                        <th wire:click="sortColumn('margin')" class="{{ $thBase }} whitespace-nowrap">
                            <div class="{{ $thInnerR }}">
                                <button
                                    wire:click.stop="toggleMarginFormat"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-sm font-semibold text-gray-950 hover:text-gray-700 transition-colors"
                                    title="Перемкнути формат маржі"
                                >
                                    Маржа
                                    <span class="text-[10px] font-bold px-1 py-0.5 rounded bg-gray-200 text-gray-600">
                                        {{ $marginFormat === 'percent' ? '%' : '₴' }}
                                    </span>
                                </button>
                                <x-dynamic-component :component="$sortIconComponent('margin')" :class="$sortIconClass('margin')" />
                            </div>
                        </th>

                        <th class="px-3 py-3.5 whitespace-nowrap w-36">
                            <span class="text-sm font-semibold text-gray-950">Кількість</span>
                        </th>
                        <th class="px-3 py-3.5 whitespace-nowrap w-32">
                            <span class="text-sm font-semibold text-gray-950">Замовити</span>
                        </th>
                    </tr>
                </thead>
                {{-- tbody: divide-gray-200 matches fi-ta-table; whitespace-nowrap matches admin --}}
                <tbody class="divide-y divide-gray-200 whitespace-nowrap bg-white">
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
                        <tr class="hover:bg-gray-50 transition-colors duration-75">

                            {{-- Фото (optional) — opens admin-style JS lightbox --}}
                            @if(! in_array('photo', $hiddenColumns))
                                <td class="px-3 py-4">
                                    <button
                                        type="button"
                                        @if($photo)
                                            onclick="event.stopPropagation();bpOpenLightbox('{{ e($photo) }}','{{ e($product->name) }}')"
                                            style="cursor:zoom-in;"
                                            title="Натисніть для збільшення"
                                        @else
                                            style="cursor:default;"
                                        @endif
                                        class="block w-12 h-12 overflow-hidden rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400"
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
                            <td class="px-3 py-4 font-mono text-xs text-gray-500 whitespace-nowrap">
                                {{ $product->sku }}
                            </td>

                            {{-- Назва --}}
                            <td class="px-3 py-4 max-w-xs whitespace-normal">
                                <a href="{{ route('cabinet.catalog.show', $product) }}"
                                   class="text-sm font-medium text-gray-950 hover:text-indigo-700 line-clamp-2">
                                    {{ $product->name }}
                                </a>
                            </td>

                            {{-- Категорія (optional) --}}
                            @if(! in_array('category', $hiddenColumns))
                                <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $product->category?->name ?? '—' }}
                                </td>
                            @endif

                            {{-- Бренд (optional) --}}
                            @if(! in_array('brand', $hiddenColumns))
                                <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $product->brand ?? '—' }}
                                </td>
                            @endif

                            {{-- Наявність --}}
                            <td class="px-3 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                                    {{ $badge['label'] }}
                                </span>
                            </td>

                            {{-- Ваша ціна --}}
                            <td class="px-3 py-4 text-right whitespace-nowrap">
                                @if($price)
                                    <span class="font-semibold text-indigo-700">
                                        {{ number_format($myPrice, 2, ',', ' ') }} ₴
                                    </span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- РРЦ --}}
                            <td class="px-3 py-4 text-right whitespace-nowrap">
                                @if($rrp > 0)
                                    <span class="text-gray-400 line-through">
                                        {{ number_format($rrp, 2, ',', ' ') }} ₴
                                    </span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- Маржа --}}
                            <td class="px-3 py-4 text-right whitespace-nowrap">
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
                            <td class="px-3 py-4">
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
                            <td class="px-3 py-4">
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

                {{-- Card: ring-1 ring-gray-950/5 matches admin's fi-ta-ctn container style --}}
                <div class="group bg-white rounded-xl ring-1 ring-gray-950/5 shadow-sm hover:shadow-md transition-shadow overflow-hidden flex flex-col">

                    {{-- Thumbnail — opens admin-style JS lightbox --}}
                    <div class="aspect-square bg-gray-100 overflow-hidden relative">
                        <button
                            type="button"
                            @if($photo)
                                onclick="event.stopPropagation();bpOpenLightbox('{{ e($photo) }}','{{ e($product->name) }}')"
                                style="cursor:zoom-in;"
                                title="Натисніть для збільшення"
                            @else
                                style="cursor:default;"
                            @endif
                            class="absolute inset-0 w-full h-full focus:outline-none"
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
                            <p class="text-xs text-gray-500 font-mono">{{ $product->sku }}</p>
                            <p class="text-sm font-semibold text-gray-950 line-clamp-2 flex-1">{{ $product->name }}</p>
                            @if($product->brand)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $product->brand }}</p>
                            @endif

                            {{-- Prices — indigo to stay consistent with table view and admin palette --}}
                            <div class="mt-2 flex items-baseline gap-2 flex-wrap">
                                @if($rrp > 0)
                                    <span class="text-xs text-gray-400 line-through">{{ number_format($rrp, 2, ',', ' ') }} ₴</span>
                                @endif
                                @if($myPrice > 0)
                                    <span class="text-base font-bold text-indigo-700">{{ number_format($myPrice, 2, ',', ' ') }} ₴</span>
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

</div>
