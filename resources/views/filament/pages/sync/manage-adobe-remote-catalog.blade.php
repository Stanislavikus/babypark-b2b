<x-filament-panels::page>
  <div
    x-data
    x-init="$nextTick(() => $store.sidebar.close())"
    class="space-y-2"
    data-testid="product-workbench-focus-mode"
  >
    @include('filament.pages.sync.partials.product-workbench-shell', [
      'activeView' => 'overview',
      'accountId' => $accountId,
      'accountName' => $accountName,
    ])

    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2 dark:border-white/10">
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
        @if ($snapshotId)
          <span>{{ __('product_channels.workbench.overview.products_count', ['count' => $remoteCatalogTotal]) }}</span>
          <span>{{ __('product_channels.workbench.overview.unlinked_count', ['count' => $remoteOnlyCount]) }}</span>
          @if ($capturedAt)
            <span>{{ __('product_channels.workbench.overview.updated_at', ['time' => $capturedAt]) }}</span>
          @endif
        @else
          <span>{{ __('product_channels.remote_catalog.not_scanned') }}</span>
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

    <style>
      [data-testid="product-workbench-overview-layout"].filters-open {
        display: grid;
        grid-template-columns: 12rem minmax(0, 1fr);
        gap: 0.75rem;
        align-items: start;
      }

      @media (max-width: 1023px) {
        [data-testid="product-workbench-overview-layout"].filters-open {
          display: block;
        }

        [data-testid="product-workbench-system-filters"] {
          margin-bottom: 0.75rem;
        }
      }
    </style>

    <div
      x-data="{ filtersOpen: window.matchMedia('(min-width: 1024px)').matches }"
      x-bind:class="{ 'filters-open': filtersOpen }"
      data-testid="product-workbench-overview-layout"
    >
      <div x-show="! filtersOpen" class="mb-2">
        <x-filament::button
          size="sm"
          color="gray"
          icon="heroicon-o-funnel"
          x-on:click="filtersOpen = true"
          data-testid="product-workbench-show-system-filters"
        >
          {{ __('product_channels.workbench.filters.heading') }}
        </x-filament::button>
      </div>

      <aside
        x-show="filtersOpen"
        class="rounded-xl border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900"
        data-testid="product-workbench-system-filters"
      >
        <div class="flex items-center justify-between gap-2 px-1 pb-1">
          <span class="text-sm font-medium text-gray-950 dark:text-white">
            {{ __('product_channels.workbench.filters.heading') }}
          </span>

          <x-filament::icon-button
            color="gray"
            icon="heroicon-o-chevron-left"
            :label="__('product_channels.workbench.filters.hide')"
            x-on:click="filtersOpen = false"
          />
        </div>

        <div class="space-y-1">
          <button
            type="button"
            wire:click="applyWorkbenchLinkView('all')"
            @class([
              'w-full rounded-lg px-2 py-1.5 text-left text-sm transition',
              'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->currentWorkbenchLinkView() === 'all',
              'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->currentWorkbenchLinkView() !== 'all',
            ])
            data-testid="product-workbench-filter-all"
          >
            {{ __('product_channels.workbench.filters.all') }}
          </button>

          <button
            type="button"
            wire:click="applyWorkbenchLinkView('unlinked')"
            @class([
              'w-full rounded-lg px-2 py-1.5 text-left text-sm transition',
              'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->currentWorkbenchLinkView() === 'unlinked',
              'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->currentWorkbenchLinkView() !== 'unlinked',
            ])
            data-testid="product-workbench-filter-needs-link"
          >
            {{ __('product_channels.workbench.filters.needs_link') }}
          </button>

          <button
            type="button"
            wire:click="applyWorkbenchLinkView('linked')"
            @class([
              'w-full rounded-lg px-2 py-1.5 text-left text-sm transition',
              'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->currentWorkbenchLinkView() === 'linked',
              'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->currentWorkbenchLinkView() !== 'linked',
            ])
            data-testid="product-workbench-filter-linked"
          >
            {{ __('product_channels.workbench.filters.linked') }}
          </button>
        </div>
      </aside>

      <div style="min-width: 0">
        {{ $this->table }}
      </div>
    </div>
  </div>
</x-filament-panels::page>
