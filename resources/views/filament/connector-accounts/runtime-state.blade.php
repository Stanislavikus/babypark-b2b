@php
    /** @var \App\Models\ConnectorAccount $record */
    /** @var \App\Support\Connectors\ConnectorAccountUiState $uiState */
    /** @var bool $showActiveConnectionCheck */
    $activeCheck = ($showActiveConnectionCheck ?? true)
        ? $uiState->activeConnectionCheck($record)
        : null;
    $runtimeLabel = $uiState->runtimeStatusLabel($activeCheck);
    $runtimeColor = $uiState->runtimeStatusColor($activeCheck);
    $stableLabel = ($useMagentoOverviewConnectedCopy ?? false)
        && $record->connection_status === \App\Enums\ConnectorAccountConnectionStatus::Connected
        ? __('connectors.ui.layer_a.status.connected')
        : $uiState->stableStatusLabel($record->connection_status);
    $stableColor = $uiState->stableStatusColor($record->connection_status);
@endphp

<div
    @if ($activeCheck !== null)
        wire:poll.5s="refreshConnectionState"
    @endif
    class="space-y-2"
>
    @if ($runtimeLabel !== null)
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$runtimeColor">
                {{ $runtimeLabel }}
            </x-filament::badge>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('connectors.ui.runtime.last_result_prefix') }}
            <x-filament::badge :color="$stableColor" class="inline-flex">
                {{ $stableLabel }}
            </x-filament::badge>
        </p>
    @else
        <x-filament::badge :color="$stableColor">
            {{ $stableLabel }}
        </x-filament::badge>
    @endif
    @if ($record->last_successful_check_at !== null)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('connectors.ui.layer_a.checked_at', ['time' => \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($record->last_successful_check_at)]) }}
        </p>
    @endif
</div>
