@php
    /** @var \App\Models\ConnectorAccount $record */
    /** @var \App\Support\Connectors\ConnectorAccountUiState $uiState */
    /** @var \App\Models\ConnectorDiscoveryRun|null $latestRun */
    /** @var \App\Models\ConnectorDiscoveryRun|null $latestSuccessfulRun */
    $activeRun = $uiState->activeDiscoveryRun($record);
    $refreshingLabel = $uiState->availableFieldsRefreshingLabel($activeRun);
    $refreshingColor = $uiState->discoveryRuntimeStatusColor($activeRun);
    $neverChecked = $record->last_discovery_at === null && $activeRun === null;
    $checkedAt = $uiState->availableFieldsCheckedAt($record, $latestRun);
    $fieldCount = $uiState->availableFieldsFieldCount($record, $latestRun, $latestSuccessfulRun);
    $failureMessage = $uiState->availableFieldsFailureMessage($latestRun);
@endphp

<div
    @if ($activeRun !== null)
        wire:poll.5s="{{ $pollMethod ?? 'refreshDiscoveryState' }}"
    @endif
    class="space-y-3"
>
    @if ($neverChecked)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('connectors.ui.available_fields.never_checked') }}
        </p>
    @elseif ($refreshingLabel !== null)
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$refreshingColor">
                {{ $refreshingLabel }}
            </x-filament::badge>
        </div>
    @else
        @if ($failureMessage !== null)
            <p class="text-sm text-danger-600 dark:text-danger-400">
                {{ $failureMessage }}
            </p>
        @endif

        @if ($checkedAt !== null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.available_fields.checked_at') }}:</span>
                {{ $checkedAt }}
            </p>
        @endif

        @if ($fieldCount !== null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium text-gray-950 dark:text-white">{{ __('connectors.ui.available_fields.field_count') }}:</span>
                {{ $fieldCount }}
            </p>
        @endif
    @endif
</div>
