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
    <x-filament.product-workbench-header
      :account-name="$accountName"
      :status-items="$snapshotId
        ? [
            [
              'icon' => 'heroicon-o-cube',
              'value' => (string) $remoteCatalogTotal,
              'tooltip' => __('product_channels.workbench.overview.catalog_tooltip', ['count' => $remoteCatalogTotal]),
            ],
            [
              'icon' => 'heroicon-o-link-slash',
              'value' => (string) $remoteOnlyCount,
              'tooltip' => __('product_channels.workbench.overview.unlinked_tooltip', ['count' => $remoteOnlyCount]),
              'tone' => $remoteOnlyCount > 0 ? 'warning' : null,
            ],
            [
              'icon' => 'heroicon-o-clock',
              'value' => $capturedAt ?: '—',
              'tooltip' => __('product_channels.workbench.overview.updated_tooltip', ['time' => $capturedAt ?: '—']),
            ],
          ]
        : [
            [
              'icon' => 'heroicon-o-clock',
              'value' => __('product_channels.remote_catalog.not_scanned'),
              'tooltip' => __('product_channels.remote_catalog.not_scanned'),
              'tone' => 'warning',
            ],
          ]"
    >
      @if ($canRefreshRemoteCatalog)
        <x-slot name="actions">
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
        </x-slot>
      @endif
    </x-filament.product-workbench-header>

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
