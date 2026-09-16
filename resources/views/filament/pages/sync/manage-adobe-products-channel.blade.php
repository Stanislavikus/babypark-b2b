<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament::section>
      <div class="space-y-3">
        <div>
          <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('product_channels.channel.context', [
              'platform' => $platformName,
              'account' => $accountName,
            ]) }}
          </p>
          <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
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

          @if ($canManageSelection)
            <x-filament::button
              tag="a"
              color="gray"
              :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportSetup::getUrl(['account' => $accountId])"
              data-testid="product-channel-open-setup"
            >
              {{ __('product_channels.channel.open_setup') }}
            </x-filament::button>
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

    <x-filament::section>
      <div class="space-y-3" data-testid="product-channel-remote-catalog">
        <div>
          <p class="font-medium text-gray-950 dark:text-white">
            {{ __('product_channels.remote_catalog.heading') }}
          </p>

          @if ($hasRemoteCatalogSnapshot)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.remote_catalog.summary', [
                'remote' => $remoteCatalogTotal,
                'linked' => $linkedRemoteCount,
                'unlinked' => $remoteOnlyCount,
              ]) }}
            </p>
          @else
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
              {{ __('product_channels.remote_catalog.not_scanned') }}
            </p>
          @endif
        </div>

        <div class="flex flex-wrap gap-2">
          @if ($canManageSelection)
            <x-filament::button
              color="gray"
              wire:click="refreshRemoteCatalog"
              :disabled="$remoteCatalogScanRunning"
              data-testid="product-channel-refresh-remote-catalog"
            >
              {{ $remoteCatalogScanRunning
                ? __('product_channels.remote_catalog.scan_running')
                : __('product_channels.remote_catalog.refresh') }}
            </x-filament::button>
          @endif

          @if ($hasRemoteCatalogSnapshot)
            <x-filament::button
              tag="a"
              color="gray"
              :href="\App\Filament\Pages\Sync\ManageAdobeRemoteCatalog::getUrl(['account' => $accountId])"
              data-testid="product-channel-open-remote-catalog"
            >
              {{ __('product_channels.remote_catalog.open_remote_only') }}
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
