@php
    /** @var \App\Models\ConnectorAccount $record */
    /** @var \App\Support\Connectors\ConnectorAccountUiState $uiState */
    /** @var \App\Models\ConnectorDiscoveryRun|null $latestRun */
    $activeRun = $uiState->activeDiscoveryRun($record);
    $runtimeLabel = $uiState->discoveryRuntimeStatusLabel($activeRun);
    $runtimeColor = $uiState->discoveryRuntimeStatusColor($activeRun);
    $neverDiscovered = $record->last_discovery_at === null && $activeRun === null;
@endphp

<div
    @if ($activeRun !== null)
        wire:poll.5s="refreshDiscoveryState"
    @endif
    class="space-y-3"
>
    @if ($neverDiscovered)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('connectors.ui.discovery.empty_state') }}
        </p>
    @elseif ($runtimeLabel !== null)
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$runtimeColor">
                {{ $runtimeLabel }}
            </x-filament::badge>
        </div>
    @elseif ($latestRun !== null)
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$uiState->discoveryStatusColor($latestRun->status)">
                {{ $uiState->discoveryStatusLabel($latestRun->status) }}
            </x-filament::badge>
        </div>

        @if ($latestRun->status === \App\Enums\ConnectorDiscoveryRunStatus::Failed)
            @php($errorMessage = $uiState->discoveryErrorMessage($latestRun))
            @if ($errorMessage !== null)
                <p class="text-sm text-danger-600 dark:text-danger-400">
                    {{ $errorMessage }}
                </p>
            @endif
        @endif

        @if ($record->last_discovery_at !== null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.last_discovery') }}:</span>
                {{ \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($record->last_discovery_at) }}
            </p>
        @endif

        @if ($record->last_successful_discovery_at !== null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.last_successful_discovery') }}:</span>
                {{ \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($record->last_successful_discovery_at) }}
            </p>
        @endif

        @if ($latestRun->status === \App\Enums\ConnectorDiscoveryRunStatus::Succeeded && $latestRun->relationLoaded('snapshot') && $latestRun->snapshot !== null)
            @php($snapshot = $latestRun->snapshot)
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <p>
                    <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.source') }}:</span>
                    {{ $uiState->schemaSourceLabel($latestRun->relationLoaded('schemaSource') ? $latestRun->schemaSource : $snapshot->schemaSource) }}
                </p>
                <p>
                    <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.field_count') }}:</span>
                    {{ $snapshot->field_count }}
                </p>
                <p>
                    <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.captured_at') }}:</span>
                    {{ \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($snapshot->captured_at) }}
                </p>
                @php($snapshotState = $uiState->snapshotStateLabel($snapshot))
                @if ($snapshotState !== null)
                    <p>
                        <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.columns.snapshot_state') }}:</span>
                        {{ $snapshotState }}
                    </p>
                @endif
                <p>
                    <a
                        href="{{ \App\Filament\Resources\ConnectorAccountResource::getUrl('view-snapshot', ['record' => $record, 'snapshot' => $snapshot]) }}"
                        class="text-primary-600 hover:text-primary-500 dark:text-primary-400"
                    >
                        {{ __('connectors.ui.snapshot.view_summary') }}
                    </a>
                </p>
            </div>
        @endif
    @endif
</div>
