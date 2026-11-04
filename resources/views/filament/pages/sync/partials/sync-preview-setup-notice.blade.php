<x-filament::section>
  <p
    class="text-sm text-gray-700 dark:text-gray-200"
    @if (! empty($testId))
      data-testid="{{ $testId }}"
    @endif
  >
    {{ __('sync_preview.states.setup_required') }}
  </p>
  @if ($canManageSetup)
    <div class="mt-3">
      <x-filament::button
        tag="a"
        :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportSetup::getUrl(['account' => $accountId])"
        data-testid="sync-preview-setup-action"
      >
        {{ __('sync_data_setup.page.open_setup') }}
      </x-filament::button>
    </div>
  @else
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
      {{ __('sync_preview.states.setup_permission_required') }}
    </p>
  @endif
</x-filament::section>
