<x-filament-panels::page>
  <div
    x-data
    x-init="
      const restoreSidebar = () => $store.sidebar.open();
      $nextTick(() => $store.sidebar.close());
      document.addEventListener('livewire:navigating', restoreSidebar, { once: true });
      window.addEventListener('pagehide', restoreSidebar, { once: true });
    "
    class="space-y-2"
    data-testid="product-workbench-focus-mode"
  >
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-1" data-testid="product-workbench-compact-header">
      <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
        <span class="text-xl font-semibold text-gray-950 dark:text-white">
          {{ __('product_channels.workbench.title') }}
        </span>
        <span class="truncate text-sm text-gray-500 dark:text-gray-400">
          {{ $accountName }}
        </span>
        <span class="text-xs text-gray-500 dark:text-gray-400">
          · {{ __('product_channels.workbench.publication.summary', [
            'selected' => $selectedProductCount,
            'total' => $masterProductCount,
          ]) }}
        </span>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        @if ($canManageSelection)
          <x-filament::button
            size="sm"
            wire:click="selectProducts"
            data-testid="product-channel-select-products"
          >
            {{ __('product_channels.channel.select_products') }}
          </x-filament::button>
        @endif

        @if ($canOpenExecution && $selectedProductCount > 0)
          <x-filament::button
            size="sm"
            tag="a"
            color="gray"
            icon="heroicon-o-check-circle"
            :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportPreview::getUrl(['account' => $accountId])"
            data-testid="product-channel-open-preview"
          >
            {{ __('product_channels.channel.open_preview') }}
          </x-filament::button>
        @endif
      </div>
    </div>

    @include('filament.pages.sync.partials.product-workbench-shell', [
      'activeView' => 'publication',
      'accountId' => $accountId,
    ])

    @if ($selectedProductCount === 0)
      <div class="rounded-xl border border-gray-200 px-3 py-2 dark:border-white/10" data-testid="product-channel-empty-selection">
        <p class="text-sm font-medium text-gray-950 dark:text-white">
          {{ __('product_channels.channel.empty_title') }}
        </p>
        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
          {{ __('product_channels.channel.empty_body') }}
        </p>
      </div>
    @elseif (! $canManageSelection)
      <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('product_channels.channel.selection_permission_required') }}
      </p>
    @endif

    @if ($selectedProductCount > 0)
      {{ $this->table }}
    @endif
  </div>
</x-filament-panels::page>
