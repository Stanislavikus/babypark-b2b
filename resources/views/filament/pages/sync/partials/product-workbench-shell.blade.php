@php
    $activeView = $activeView ?? 'overview';
@endphp

<div class="space-y-3" data-testid="product-workbench-shell">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ __('product_channels.workbench.account', ['account' => $accountName]) }}
            </p>
        </div>
    </div>

    <x-filament::tabs :contained="true">
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
