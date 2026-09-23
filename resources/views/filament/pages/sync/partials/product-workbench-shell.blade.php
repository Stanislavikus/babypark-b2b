@php
    $activeView = $activeView ?? 'overview';
@endphp

<nav
    class="flex items-end gap-1 border-b border-gray-200 dark:border-white/10"
    aria-label="{{ __('product_channels.workbench.title') }}"
    data-testid="product-workbench-shell"
>
    <a
        href="{{ \App\Filament\Pages\Sync\ManageAdobeRemoteCatalog::getUrl(['account' => $accountId]) }}"
        @class([
            '-mb-px rounded-t-lg border px-4 py-2 text-sm font-medium transition',
            'border-gray-200 border-b-white bg-white text-primary-600 shadow-sm dark:border-white/10 dark:border-b-gray-900 dark:bg-gray-900 dark:text-primary-400' => $activeView === 'overview',
            'border-transparent text-gray-500 hover:border-gray-200 hover:bg-gray-50 hover:text-gray-800 dark:text-gray-400 dark:hover:border-white/10 dark:hover:bg-white/5 dark:hover:text-gray-200' => $activeView !== 'overview',
        ])
        data-testid="product-workbench-tab-overview"
    >
        {{ __('product_channels.workbench.tabs.overview') }}
    </a>

    <a
        href="{{ \App\Filament\Pages\Sync\ManageAdobeProductsChannel::getUrl(['account' => $accountId]) }}"
        @class([
            '-mb-px rounded-t-lg border px-4 py-2 text-sm font-medium transition',
            'border-gray-200 border-b-white bg-white text-primary-600 shadow-sm dark:border-white/10 dark:border-b-gray-900 dark:bg-gray-900 dark:text-primary-400' => $activeView === 'publication',
            'border-transparent text-gray-500 hover:border-gray-200 hover:bg-gray-50 hover:text-gray-800 dark:text-gray-400 dark:hover:border-white/10 dark:hover:bg-white/5 dark:hover:text-gray-200' => $activeView !== 'publication',
        ])
        data-testid="product-workbench-tab-publication"
    >
        {{ __('product_channels.workbench.tabs.publication') }}
    </a>
</nav>
