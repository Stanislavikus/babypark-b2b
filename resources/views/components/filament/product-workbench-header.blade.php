@props([
    'accountName',
    'statusItems' => [],
])

<div
    {{ $attributes->class([
        'grid items-center gap-2 py-1 lg:grid-cols-[minmax(0,auto)_minmax(0,1fr)_auto]',
    ]) }}
    data-testid="product-workbench-compact-header"
>
    <div class="flex min-w-0 items-center gap-2" data-testid="product-workbench-identity">
        <img
            src="{{ asset('images/connectors/magento-mark.png') }}"
            alt=""
            width="28"
            height="28"
            style="width: 28px; height: 28px;"
            class="shrink-0 object-contain"
            aria-hidden="true"
        >

        <div class="flex min-w-0 items-baseline gap-2 whitespace-nowrap">
            <span class="text-xl font-semibold text-gray-950 dark:text-white">
                {{ __('product_channels.workbench.title') }}
            </span>
            <span class="truncate text-sm text-gray-500 dark:text-gray-400">
                {{ $accountName }}
            </span>
        </div>
    </div>

    @if (count($statusItems) > 0)
        <div
            class="flex min-w-0 flex-wrap items-center justify-start gap-1.5 lg:justify-self-center"
            data-testid="product-workbench-status-board"
        >
            @foreach ($statusItems as $item)
                <span
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium ring-1 ring-inset',
                        'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400' => ($item['tone'] ?? null) === 'warning',
                        'bg-gray-50 text-gray-700 ring-gray-600/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => ($item['tone'] ?? null) !== 'warning',
                    ])
                    title="{{ $item['tooltip'] ?? '' }}"
                    @if (! empty($item['tooltip'])) aria-label="{{ $item['tooltip'] }}" @endif
                >
                    @if (! empty($item['icon']))
                        <x-filament::icon :icon="$item['icon']" class="h-4 w-4 shrink-0" />
                    @endif
                    <span class="whitespace-nowrap">{{ $item['value'] ?? '—' }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if (isset($actions) && ! \Filament\Support\is_slot_empty($actions))
        <div class="flex shrink-0 flex-wrap items-center justify-start gap-2 lg:justify-self-end">
            {{ $actions }}
        </div>
    @endif
</div>
