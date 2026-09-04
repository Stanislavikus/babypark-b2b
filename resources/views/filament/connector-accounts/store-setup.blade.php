@php
    /** @var \App\Models\ConnectorAccount $record */
    /** @var \App\Support\Connectors\ConnectorAccountUiState $uiState */
    $latestSuccessfulCheck = $record->connectionChecks()
        ->where('status', \App\Enums\ConnectorConnectionCheckStatus::Succeeded)
        ->latest('finished_at')
        ->first();
    $params = $latestSuccessfulCheck?->safe_message_parameters;
    $catalogCountKnown = is_array($params)
        && array_key_exists('catalog_total_count', $params)
        && is_int($params['catalog_total_count']);
    $catalogTotalCount = $catalogCountKnown ? $params['catalog_total_count'] : null;
    $imagesAccessConfirmed = is_array($params) && ($params['images_access_confirmed'] ?? false) === true;
    $fieldsAccessConfirmed = $record->last_successful_discovery_at !== null;
    $syncConfigurationId = $record->syncConfigurations()->orderByDesc('created_at')->value('id');
    $activeCheck = ($showActiveConnectionCheck ?? true) ? $uiState->activeConnectionCheck($record) : null;
    $statusLabel = $uiState->runtimeStatusLabel($activeCheck) ?? $uiState->stableStatusLabel($record->connection_status);
    $statusColor = $uiState->runtimeStatusColor($activeCheck) ?? $uiState->stableStatusColor($record->connection_status);
@endphp

<div @if ($activeCheck !== null) wire:poll.5s="refreshConnectionState" @endif class="space-y-6">
    <div class="space-y-2">
        <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
        @if ($record->last_successful_check_at !== null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('connectors.ui.layer_a.checked_at', ['time' => \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($record->last_successful_check_at)]) }}
            </p>
        @endif
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('connectors.ui.layer_a.check_does_not_mutate') }}</p>
    </div>

    <div class="space-y-4 rounded-xl border border-gray-200 bg-white/70 p-4 dark:border-white/10 dark:bg-black/10">
        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.what_we_checked_heading') }}</p>

        <div class="space-y-4 text-sm">
            <div>
                <p class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.catalog.label') }}</p>
                <p>{{ $catalogCountKnown ? __('connectors.ui.layer_a.status.access_confirmed') : __('connectors.ui.layer_a.status.not_checked') }}</p>
                @if ($catalogCountKnown)
                    <p class="text-gray-600 dark:text-gray-400">{{ $catalogTotalCount === 0 ? __('connectors.ui.layer_a.catalog.empty') : __('connectors.ui.layer_a.catalog.found_count', ['count' => $catalogTotalCount]) }}</p>
                @endif
            </div>

            <div>
                <p class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.fields.label') }}</p>
                <p>{{ $fieldsAccessConfirmed ? __('connectors.ui.layer_a.status.access_confirmed') : __('connectors.ui.layer_a.status.not_checked') }}</p>
                @if ($fieldsAccessConfirmed && $syncConfigurationId !== null)
                    <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Pages\Sync\ManageSyncFieldMappings::getUrl(['account' => (string) $record->id, 'configuration' => (string) $syncConfigurationId]) }}">{{ __('connectors.ui.layer_a.actions.view_fields') }}</a>
                @endif
            </div>

            <div>
                <p class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.images.label') }}</p>
                <p>{{ $imagesAccessConfirmed ? __('connectors.ui.layer_a.status.access_confirmed') : __('connectors.ui.layer_a.status.not_checked') }}</p>
            </div>
        </div>
    </div>

    <div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('connectors.ui.layer_a.next_step.heading') }}</p>
        @if ($syncConfigurationId === null && ($canConfigureSync ?? false))
            <x-filament::button tag="a" :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportSetup::getUrl(['account' => $record->getKey()])">
                {{ __('connectors.ui.layer_a.next_step.configure') }}
            </x-filament::button>
        @elseif ($syncConfigurationId !== null && ($canCreatePreview ?? false))
            <x-filament::button tag="a" :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportPreview::getUrl(['account' => $record->getKey()])">
                {{ __('connectors.ui.layer_a.next_step.preview') }}
            </x-filament::button>
        @endif
    </div>
</div>
