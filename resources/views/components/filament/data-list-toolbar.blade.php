@props([
    'filtersCount' => 0,
    'hasFilters' => false,
])

<div
    {{ $attributes->class([
        'fi-data-list-toolbar space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
    ]) }}
>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        @if (isset($search))
            <div data-toolbar-region="search" class="min-w-0 w-full flex-1">
                {{ $search }}
            </div>
        @endif

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($hasFilters && isset($filters))
                <div data-toolbar-region="filters">
                    <x-filament::dropdown placement="bottom-end" shift width="md">
                        <x-slot name="trigger">
                            <x-filament::button
                                color="gray"
                                icon="heroicon-m-funnel"
                                data-testid="data-list-filter-trigger"
                            >
                                <span class="flex items-center gap-2">
                                    <span>Фільтри</span>
                                    @if ($filtersCount > 0)
                                        <x-filament::badge color="primary" size="sm">
                                            {{ $filtersCount }}
                                        </x-filament::badge>
                                    @endif
                                </span>
                            </x-filament::button>
                        </x-slot>

                        <div class="space-y-4 p-4">
                            {{ $filters }}
                        </div>
                    </x-filament::dropdown>
                </div>
            @endif

            @if (isset($actions))
                <div data-toolbar-region="actions" class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endif
        </div>
    </div>

    @if (isset($activeFilters) && ! \Filament\Support\is_slot_empty($activeFilters))
        <div data-toolbar-region="active-filters" class="flex flex-wrap items-center gap-2">
            {{ $activeFilters }}
        </div>
    @endif
</div>
