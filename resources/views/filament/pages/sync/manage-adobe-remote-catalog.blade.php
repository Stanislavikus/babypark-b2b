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

        @if ($snapshotId)
          <span class="text-xs text-gray-500 dark:text-gray-400">
            · {{ __('product_channels.workbench.overview.products_count', ['count' => $remoteCatalogTotal]) }}
          </span>
          <span class="text-xs text-gray-500 dark:text-gray-400">
            · {{ __('product_channels.workbench.overview.unlinked_count', ['count' => $remoteOnlyCount]) }}
          </span>
          @if ($capturedAt)
            <span class="text-xs text-gray-500 dark:text-gray-400">
              · {{ __('product_channels.workbench.overview.updated_at', ['time' => $capturedAt]) }}
            </span>
          @endif
        @else
          <span class="text-xs text-gray-500 dark:text-gray-400">
            · {{ __('product_channels.remote_catalog.not_scanned') }}
          </span>
        @endif
      </div>

      @if ($canRefreshRemoteCatalog)
        <x-filament::button
          size="sm"
          color="gray"
          icon="heroicon-o-arrow-path"
          wire:click="refreshRemoteCatalog"
          :disabled="$remoteCatalogScanRunning"
          data-testid="product-workbench-refresh-catalog"
        >
          {{ $remoteCatalogScanRunning
            ? __('product_channels.remote_catalog.scan_running')
            : __('product_channels.workbench.overview.refresh') }}
        </x-filament::button>
      @endif
    </div>

    @include('filament.pages.sync.partials.sync-live-entity-trust', [
      'entityTrustHideWorkingSet' => true,
    ])

    @include('filament.pages.sync.partials.product-workbench-shell', [
      'activeView' => 'overview',
      'accountId' => $accountId,
    ])

    {{ $this->table }}
  </div>
</x-filament-panels::page>
