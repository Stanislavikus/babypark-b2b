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
      :status-items="[
        [
          'icon' => 'heroicon-o-check-badge',
          'value' => $selectedProductCount.' / '.$masterProductCount,
          'tooltip' => __('product_channels.workbench.publication.selected_tooltip', [
            'selected' => $selectedProductCount,
            'total' => $masterProductCount,
          ]),
          'tone' => $selectedProductCount < $masterProductCount ? 'warning' : null,
        ],
      ]"
    >
      <x-slot name="actions">
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
      </x-slot>
    </x-filament.product-workbench-header>

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
