<x-filament-panels::page>
  <div
    x-data
    x-init="$nextTick(() => $store.sidebar.close())"
    class="space-y-4"
    data-testid="product-workbench-focus-mode"
  >
    @include('filament.pages.sync.partials.product-workbench-shell', [
      'activeView' => 'publication',
      'accountId' => $accountId,
      'accountName' => $accountName,
    ])

    <x-filament::section>
      <div class="space-y-3">
        <div>
          <p class="font-medium text-gray-950 dark:text-white">
            {{ __('product_channels.workbench.publication.purpose') }}
          </p>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {{ __('product_channels.channel.summary', [
              'selected' => $selectedProductCount,
              'total' => $masterProductCount,
            ]) }}
          </p>
        </div>

        @if ($selectedProductCount === 0)
          <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" data-testid="product-channel-empty-selection">
            <p class="font-medium text-gray-950 dark:text-white">
              {{ __('product_channels.channel.empty_title') }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.channel.empty_body') }}
            </p>
          </div>
        @endif

        <div class="flex flex-wrap gap-2">
          @if ($canManageSelection)
            <x-filament::button wire:click="selectProducts" data-testid="product-channel-select-products">
              {{ __('product_channels.channel.select_products') }}
            </x-filament::button>
          @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.channel.selection_permission_required') }}
            </p>
          @endif

          @if ($canOpenExecution && $selectedProductCount > 0)
            <x-filament::button
              tag="a"
              color="gray"
              :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportPreview::getUrl(['account' => $accountId])"
              data-testid="product-channel-open-preview"
            >
              {{ __('product_channels.channel.open_preview') }}
            </x-filament::button>
          @endif
        </div>
      </div>
    </x-filament::section>

    @if ($selectedProductCount > 0)
      {{ $this->table }}
    @endif
  </div>
</x-filament-panels::page>
