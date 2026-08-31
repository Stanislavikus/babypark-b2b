@php
    /** @var \App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount $this */
    $actionState = $this->storeSetupActionState();
    $baselineMessage = $this->storeSetupBaselineMessage;
    $state = $this->storeSetupState;
    $moduleVersion = $this->storeSetupModuleVersion;
    $applicationVersion = $this->storeSetupApplicationVersion;
    $phpVersion = $this->storeSetupPhpVersion;

    $detailsParts = array_values(array_filter([
        filled($applicationVersion) ? __('connectors.ui.readiness.details.magento', ['version' => $applicationVersion]) : null,
        filled($phpVersion) ? __('connectors.ui.readiness.details.php', ['version' => $phpVersion]) : null,
        filled($moduleVersion) ? __('connectors.ui.readiness.details.extension', ['version' => $moduleVersion]) : null,
    ], fn (?string $value): bool => filled($value)));

    $detailsLine = $detailsParts !== [] ? implode(' · ', $detailsParts) : null;

    $containerClasses = match ($state) {
        'READY' => 'border-success-200 bg-success-50/70 dark:border-success-500/30 dark:bg-success-500/10',
        'SETUP_REQUIRED', 'UPDATE_REQUIRED' => 'border-warning-200 bg-warning-50/70 dark:border-warning-500/30 dark:bg-warning-500/10',
        'BASELINE_FAILURE' => 'border-danger-200 bg-danger-50/70 dark:border-danger-500/30 dark:bg-danger-500/10',
        default => 'border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5',
    };

    $titleKey = match ($state) {
        'READY' => 'connectors.ui.readiness.ready.title',
        'SETUP_REQUIRED' => 'connectors.ui.readiness.setup_required.title',
        'UPDATE_REQUIRED' => 'connectors.ui.readiness.update_required.title',
        'BASELINE_FAILURE' => 'connectors.ui.readiness.baseline_failure.title',
        default => null,
    };

    $bodyKey = match ($state) {
        'READY' => 'connectors.ui.readiness.ready.body',
        'SETUP_REQUIRED' => 'connectors.ui.readiness.setup_required.body',
        'UPDATE_REQUIRED' => 'connectors.ui.readiness.update_required.body',
        default => null,
    };
@endphp

<div class="space-y-2">
    <div
        class="rounded-xl border p-4 {{ $containerClasses }}"
        wire:loading.class="opacity-70"
    >
        <div wire:loading.remove class="space-y-3">
            @if ($titleKey !== null)
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ __($titleKey) }}
                </p>
            @endif

            @if ($bodyKey !== null)
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __($bodyKey) }}
                </p>

                @if ($detailsLine !== null)
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        {{ $detailsLine }}
                    </p>
                @endif
            @elseif ($state === 'BASELINE_FAILURE')
                <div class="space-y-3">
                    <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <p>{{ $baselineMessage }}</p>
                        <p>{{ __('connectors.ui.readiness.baseline_failure.guidance') }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        {{ $this->checkStoreSetupAction }}

                        @if (filled($actionState['disabled_reason']))
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ $actionState['disabled_reason'] }}
                            </span>
                        @endif
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        {{ __('connectors.ui.readiness.not_checked.body') }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        {{ $this->checkStoreSetupAction }}

                        @if (filled($actionState['disabled_reason']))
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ $actionState['disabled_reason'] }}
                            </span>
                        @endif
                    </div>
                </div>
            @endif

            @if ($bodyKey !== null)
                <div class="flex flex-wrap items-center gap-3">
                    {{ $this->checkStoreSetupAction }}

                    @if (filled($actionState['disabled_reason']))
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $actionState['disabled_reason'] }}
                        </span>
                    @endif
                </div>
            @endif
        </div>

        <div wire:loading.flex class="hidden items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>{{ __('connectors.ui.readiness.checking') }}</span>
        </div>
    </div>
</div>
