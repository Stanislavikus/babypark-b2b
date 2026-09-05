@php
    /** @var \App\Models\ConnectorAccount $record */
    $record = $record ?? null;

    $syncConfigurationId = $syncConfigurationId ?? null;
    $canConfigureSync = $canConfigureSync ?? false;
    $canCreatePreview = $canCreatePreview ?? false;
    $canManageSyncConfiguration = $canManageSyncConfiguration ?? false;
    $canRunPreview = $canRunPreview ?? false;
@endphp

<div class="space-y-4">
    <div class="space-y-2">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            {{ __('connectors.ui.layer_a.check_does_not_mutate') }}
        </p>
    </div>

    <div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.next_step.heading') }}</p>
        @if ($syncConfigurationId === null && $canConfigureSync)
            <x-filament::button tag="a" :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportSetup::getUrl(['account' => $record->getKey()])">
                {{ __('connectors.ui.layer_a.next_step.configure') }}
            </x-filament::button>
        @elseif ($syncConfigurationId !== null && $canCreatePreview)
            <x-filament::button tag="a" :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportPreview::getUrl(['account' => $record->getKey()])">
                {{ __('connectors.ui.layer_a.next_step.preview') }}
            </x-filament::button>
        @elseif ($syncConfigurationId === null && ! $canManageSyncConfiguration)
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('connectors.ui.layer_a.next_step.setup_admin_required') }}</p>
        @elseif ($syncConfigurationId !== null && ! $canRunPreview)
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('connectors.ui.layer_a.next_step.preview_permission_required') }}</p>
        @else
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('connectors.ui.layer_a.next_step.unavailable') }}</p>
        @endif
    </div>
</div>
