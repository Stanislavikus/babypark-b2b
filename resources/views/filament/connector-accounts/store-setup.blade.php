@php
    /** @var \App\Models\ConnectorAccount $record */
    $record = $record ?? null;

    $syncConfigurationId = $syncConfigurationId ?? null;
    $canConfigureSync = $canConfigureSync ?? false;
    $canCreatePreview = $canCreatePreview ?? false;
    $canManageSyncConfiguration = $canManageSyncConfiguration ?? false;
    $canRunPreview = $canRunPreview ?? false;
    $productWritePaused = $productWritePaused ?? false;
@endphp

<div class="space-y-4">
    <div class="space-y-2">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            {{ __('connectors.ui.layer_a.check_does_not_mutate') }}
        </p>
    </div>

    @if ($productWritePaused)
        <div class="space-y-2 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
            <p class="text-sm font-medium text-warning-800 dark:text-warning-300">
                {{ __('connectors.ui.layer_a.product_write_paused.title') }}
            </p>
            <p class="text-sm text-warning-800 dark:text-warning-300">
                {{ __('connectors.ui.layer_a.product_write_paused.body') }}
            </p>
            <p class="text-sm font-medium text-warning-900 dark:text-warning-200">
                {{ __('connectors.ui.layer_a.product_write_paused.remediation') }}
            </p>
        </div>
    @else
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
    @endif
</div>
