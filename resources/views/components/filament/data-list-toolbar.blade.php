@props([
    'filtersCount' => 0,
    'hasFilters' => false,
    'panelId' => null,
    'mobileContextIndicator' => null,
    'filtersLabel' => 'Фільтри',
])

@php
    $hasPanel = filled($panelId) && isset($panel);
    $showMobileOverflow = $hasPanel || $hasFilters || isset($actions) || isset($desktop);
    $mobileBadge = $hasFilters && $filtersCount > 0
        ? (string) $filtersCount
        : ($mobileContextIndicator ?? null);
@endphp

<div
    x-data="{ panelFocus: 'all' }"
    {{ $attributes->class([
        'fi-data-list-toolbar space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
    ]) }}
>
    <div data-toolbar-row="header" class="flex items-center gap-2">
        @if (isset($search))
            <div data-toolbar-region="search" class="min-w-0 flex-1">
                {{ $search }}
            </div>
        @endif

        <div class="hidden shrink-0 items-center gap-2 md:flex">
            @if ($hasFilters)
                <div data-toolbar-region="filters">
                    @if ($hasPanel)
                        <x-filament::button
                            color="gray"
                            icon="heroicon-m-funnel"
                            data-testid="data-list-filter-trigger"
                            x-on:click="panelFocus = 'filters'; $dispatch('open-modal', { id: '{{ $panelId }}' })"
                        >
                            <span class="flex items-center gap-2">
                                <span>{{ $filtersLabel }}</span>
                                @if ($filtersCount > 0)
                                    <x-filament::badge color="primary" size="sm">
                                        {{ $filtersCount }}
                                    </x-filament::badge>
                                @endif
                            </span>
                        </x-filament::button>
                    @else
                        <x-filament::button
                            color="gray"
                            icon="heroicon-m-funnel"
                            data-testid="data-list-filter-trigger"
                        >
                            <span class="flex items-center gap-2">
                                <span>{{ $filtersLabel }}</span>
                                @if ($filtersCount > 0)
                                    <x-filament::badge color="primary" size="sm">
                                        {{ $filtersCount }}
                                    </x-filament::badge>
                                @endif
                            </span>
                        </x-filament::button>
                    @endif
                </div>
            @endif

            @if (isset($actions))
                <div data-toolbar-region="actions" class="flex items-center gap-2">
                    {{ $actions }}
                </div>
            @endif

            @if (isset($desktop))
                {{ $desktop }}
            @endif
        </div>

        @if ($showMobileOverflow)
            <div class="shrink-0 md:hidden" data-toolbar-region="mobile-overflow">
                @if ($hasPanel)
                    <x-filament::icon-button
                        color="gray"
                        icon="heroicon-o-bars-3"
                        label="Додаткові дії"
                        data-testid="data-list-mobile-overflow-trigger"
                        :badge="$mobileBadge"
                        x-on:click="panelFocus = 'all'; $dispatch('open-modal', { id: '{{ $panelId }}' })"
                    />
                @else
                    <x-filament::icon-button
                        color="gray"
                        icon="heroicon-o-bars-3"
                        label="Додаткові дії"
                        data-testid="data-list-mobile-overflow-trigger"
                        :badge="$mobileBadge"
                    />
                @endif
            </div>
        @endif
    </div>

    @if (isset($activeFilters) && ! \Filament\Support\is_slot_empty($activeFilters))
        <div data-toolbar-region="active-filters" class="flex flex-wrap items-center gap-2">
            {{ $activeFilters }}
        </div>
    @endif

    @if ($hasPanel)
        <x-filament::modal :id="$panelId" width="md" slide-over>
            {{ $panel }}
        </x-filament::modal>
    @endif
</div>
