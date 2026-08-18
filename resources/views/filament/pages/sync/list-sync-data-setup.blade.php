<x-filament-panels::page>
  <div class="space-y-4">
    @if ($targets === [])
      <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="sync-data-setup-empty">
          {{ __('sync_data_setup.page.empty') }}
        </p>
      </x-filament::section>
    @else
      <x-filament::section>
        <div class="space-y-3">
          @foreach ($targets as $target)
            <div
              class="flex flex-col gap-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between"
              data-testid="sync-data-setup-target-{{ $target['account_id'] }}"
            >
              <div class="space-y-1 text-sm text-gray-700 dark:text-gray-200">
                <p class="font-medium">{{ $target['target_label'] }}</p>
                <p>
                  {{ __('sync_data_setup.page.target_context', [
                    'platform' => $target['platform_name'],
                    'account' => $target['account_name'],
                  ]) }}
                </p>
                @if (! $target['setup_usable'])
                  <p class="text-warning-600 dark:text-warning-400">
                    {{ __('sync_data_setup.adobe_products_export.account_unavailable') }}
                  </p>
                @endif
              </div>

              <x-filament::button
                tag="a"
                :href="$target['setup_url']"
                color="gray"
                data-testid="sync-data-setup-open-{{ $target['account_id'] }}"
              >
                {{ __('sync_data_setup.page.open_setup') }}
              </x-filament::button>
            </div>
          @endforeach
        </div>
      </x-filament::section>
    @endif
  </div>
</x-filament-panels::page>
