@php
    $activeView = $activeView ?? 'overview';
@endphp

<div class="space-y-2" data-testid="product-workbench-shell">
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::icon-button
            color="gray"
            icon="heroicon-o-bars-3"
            :label="__('product_channels.workbench.open_navigation')"
            x-on:click="$store.sidebar.open()"
            data-testid="product-workbench-open-navigation"
        />

        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
            <span class="text-xl font-semibold text-gray-950 dark:text-white">
                {{ __('product_channels.workbench.title') }}
            </span>
            <span class="truncate text-sm text-gray-500 dark:text-gray-400">
                {{ $accountName }}
            </span>
        </div>
    </div>

    <x-filament::tabs :contained="false">
        <x-filament::tabs.item
            tag="a"
            :href="\App\Filament\Pages\Sync\ManageAdobeRemoteCatalog::getUrl(['account' => $accountId])"
            :active="$activeView === 'overview'"
            data-testid="product-workbench-tab-overview"
        >
            {{ __('product_channels.workbench.tabs.overview') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            tag="a"
            :href="\App\Filament\Pages\Sync\ManageAdobeProductsChannel::getUrl(['account' => $accountId])"
            :active="$activeView === 'publication'"
            data-testid="product-workbench-tab-publication"
        >
            {{ __('product_channels.workbench.tabs.publication') }}
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>
