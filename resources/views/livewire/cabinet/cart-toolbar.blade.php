<div class="bp-cart-toolbar">
    @if ($count > 0)
        <x-filament::dropdown placement="bottom-end" shift width="sm">
            <x-slot name="trigger">
                <button
                    type="button"
                    class="relative inline-flex items-center rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-primary-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-primary-400"
                    title="Кошик"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.874-7.148a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/>
                    </svg>

                    <span class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary-600 px-0.5 text-[10px] font-bold leading-none text-white">
                        {{ $count > 99 ? '99+' : $count }}
                    </span>
                </button>
            </x-slot>

            <div class="p-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Кошик</p>

                <ul class="max-h-60 space-y-2 overflow-y-auto">
                    @foreach ($lines as $line)
                        <li class="flex items-start justify-between gap-2 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $line['name'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $line['sku'] }} × {{ $line['quantity'] }}</p>
                            </div>
                            <span class="shrink-0 tabular-nums text-gray-700 dark:text-gray-300">
                                @if ($line['price_available'])
                                    ₴&nbsp;{{ number_format($line['line_total'], 2, ',', ' ') }}
                                @else
                                    <span class="text-amber-600">Ціна не задана</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                    <span>Разом</span>
                    <span class="tabular-nums">₴&nbsp;{{ number_format($total, 2, ',', ' ') }}</span>
                </div>
            </div>
        </x-filament::dropdown>
    @else
        <span
            class="relative inline-flex items-center rounded-lg p-1.5 text-gray-500 dark:text-gray-400"
            title="Кошик"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.874-7.148a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/>
            </svg>
        </span>
    @endif

    <span class="text-base font-semibold tabular-nums text-gray-900 dark:text-gray-100">
        ₴&nbsp;{{ number_format($total, 2, ',', ' ') }}
    </span>
</div>
