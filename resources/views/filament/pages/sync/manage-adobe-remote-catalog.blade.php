<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament::section>
      <div class="space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">
          {{ __('product_channels.remote_catalog.summary', [
            'remote' => $remoteCatalogTotal,
            'linked' => $linkedRemoteCount,
            'unlinked' => $remoteOnlyCount,
          ]) }}
        </p>
        @if ($capturedAt)
          <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('product_channels.remote_catalog.captured_at', ['time' => $capturedAt]) }}
          </p>
        @endif
        <x-filament::button
          tag="a"
          color="gray"
          :href="\App\Filament\Pages\Sync\ManageAdobeProductsChannel::getUrl(['account' => $accountId])"
        >
          {{ __('product_channels.remote_catalog.back_to_channel') }}
        </x-filament::button>
      </div>
    </x-filament::section>

    {{ $this->table }}
  </div>
</x-filament-panels::page>
