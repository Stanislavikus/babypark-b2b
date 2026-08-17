<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament::section>
      <div class="space-y-2 text-sm text-gray-700 dark:text-gray-200">
        <p data-testid="sync-data-setup-context">
          {{ __('sync_data_setup.adobe_products_export.context', [
            'platform' => $platformName,
            'account' => $accountName,
          ]) }}
        </p>

        @if (! $setupUsable)
          <p class="text-warning-600 dark:text-warning-400" data-testid="sync-data-setup-unusable">
            {{ __('sync_data_setup.adobe_products_export.account_unavailable') }}
          </p>
        @elseif ($setupRequired)
          <p class="text-warning-600 dark:text-warning-400" data-testid="sync-data-setup-required">
            {{ __('sync_data_setup.adobe_products_export.setup_required') }}
          </p>
        @elseif ($configuredAttributeSetName)
          <p data-testid="sync-data-setup-configured">
            {{ __('sync_data_setup.adobe_products_export.current_selection', [
              'name' => $configuredAttributeSetName,
            ]) }}
          </p>
        @endif

        @if ($configuredSetStale)
          <p class="text-warning-600 dark:text-warning-400" data-testid="sync-data-setup-stale">
            {{ __('sync_data_setup.adobe_products_export.stale_selection') }}
          </p>
        @endif
      </div>
    </x-filament::section>

    <form wire:submit="save" novalidate>
      {{ $this->form }}

      <div class="mt-4">
        <x-filament::button
          type="submit"
          data-testid="sync-data-setup-save"
          :disabled="! $setupUsable"
        >
          {{ __('sync_data_setup.actions.save') }}
        </x-filament::button>
      </div>
    </form>
  </div>
</x-filament-panels::page>
