<button
    type="button"
    wire:click="sortTable('is_linked')"
    class="inline-flex items-center gap-1 font-medium text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
    aria-label="{{ __('product_channels.workbench.actions.sort_link_needed') }}"
    title="{{ __('product_channels.workbench.actions.sort_link_needed') }}"
>
    <span>{{ __('product_channels.workbench.columns.action') }}</span>

    @if ($active)
        <x-filament::icon
            :icon="$direction === 'desc' ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-up'"
            class="h-4 w-4 text-gray-400"
        />
    @else
        <x-filament::icon icon="heroicon-m-chevron-up-down" class="h-4 w-4 text-gray-400" />
    @endif
</button>
