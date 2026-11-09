<button
    type="button"
    wire:click="sortTable('is_linked')"
    wire:loading.attr="disabled"
    wire:target="sortTable('is_linked')"
    class="bp-workbench-action-sort-header fi-ta-header-cell-sort-btn"
    aria-label="{{ __('product_channels.workbench.actions.sort_link_needed') }}"
    title="{{ __('product_channels.workbench.actions.sort_link_needed') }}"
>
    <span>{{ __('product_channels.workbench.columns.action') }}</span>

    <x-filament::icon
        :icon="$active && $direction === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
        @class([
            'fi-icon h-4 w-4',
            'text-gray-950 dark:text-white' => $active,
            'text-gray-400 dark:text-gray-500' => ! $active,
        ])
        wire:loading.remove.delay
        wire:target="sortTable('is_linked')"
    />
</button>
