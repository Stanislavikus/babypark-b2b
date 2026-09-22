<x-filament-panels::page>
  <div
    x-data
    x-init="$nextTick(() => $store.sidebar.close())"
    class="space-y-4"
    data-testid="product-workbench-focus-mode"
  >
    @include('filament.pages.sync.partials.product-workbench-shell', [
      'activeView' => 'overview',
      'accountId' => $accountId,
      'accountName' => $accountName,
    ])

    <x-filament::section>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
          <p class="font-medium text-gray-950 dark:text-white">
            {{ __('product_channels.workbench.overview.purpose') }}
          </p>

          @if ($snapshotId)
            <p class="text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.workbench.overview.summary', [
                'remote' => $remoteCatalogTotal,
                'unlinked' => $remoteOnlyCount,
              ]) }}
            </p>
          @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.remote_catalog.not_scanned') }}
            </p>
          @endif

          @if ($capturedAt)
            <p class="text-xs text-gray-500 dark:text-gray-400">
              {{ __('product_channels.remote_catalog.captured_at', ['time' => $capturedAt]) }}
            </p>
          @endif
        </div>

        @if ($canRefreshRemoteCatalog)
          <x-filament::button
            color="gray"
            wire:click="refreshRemoteCatalog"
            :disabled="$remoteCatalogScanRunning"
            data-testid="product-workbench-refresh-catalog"
          >
            {{ $remoteCatalogScanRunning
              ? __('product_channels.remote_catalog.scan_running')
              : __('product_channels.remote_catalog.refresh') }}
          </x-filament::button>
        @endif
      </div>
    </x-filament::section>

    @include('filament.pages.sync.partials.sync-live-entity-trust', [
      'entityTrustHideWorkingSet' => true,
    ])

    <div
      x-data="{ filtersOpen: true }"
      class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]"
      data-testid="product-workbench-overview-layout"
    >
      <aside class="h-fit rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-2 text-left text-sm font-medium text-gray-950 dark:text-white"
          x-on:click="filtersOpen = ! filtersOpen"
          x-bind:aria-expanded="filtersOpen"
        >
          <span>{{ __('product_channels.workbench.filters.heading') }}</span>
          <span aria-hidden="true" x-text="filtersOpen ? '−' : '+'"></span>
        </button>

        <div x-show="filtersOpen" x-collapse class="mt-3 space-y-2">
          <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('product_channels.workbench.filters.system_views') }}
          </p>

          <button
            type="button"
            wire:click="applyWorkbenchLinkView('all')"
            @class([
              'w-full rounded-lg px-3 py-2 text-left text-sm transition',
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
              'w-full rounded-lg px-3 py-2 text-left text-sm transition',
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
              'w-full rounded-lg px-3 py-2 text-left text-sm transition',
              'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $this->currentWorkbenchLinkView() === 'linked',
              'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->currentWorkbenchLinkView() !== 'linked',
            ])
            data-testid="product-workbench-filter-linked"
          >
            {{ __('product_channels.workbench.filters.linked') }}
          </button>

          <p class="pt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('product_channels.workbench.filters.more_hint') }}
          </p>
        </div>
      </aside>

      <div class="min-w-0">
        {{ $this->table }}
      </div>
    </div>
  </div>
</x-filament-panels::page>
