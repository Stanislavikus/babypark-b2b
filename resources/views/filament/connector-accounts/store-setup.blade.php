@php
    /** @var \App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount $this */
    $actionState = $this->storeSetupActionState();
    $state = $this->storeSetupState;

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
        wire:target="checkStoreSetup"
    >
        <div wire:loading.remove wire:target="checkStoreSetup" class="space-y-3">
            @if ($titleKey !== null)
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ __($titleKey) }}
                </p>
            @endif

            @if ($bodyKey !== null)
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __($bodyKey) }}
                </p>
            @elseif ($state === 'BASELINE_FAILURE')
                <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <p>{{ $livewire->storeSetupBaselineMessage }}</p>
                    <p>{{ __('connectors.ui.readiness.baseline_failure.guidance') }}</p>
                </div>
            @else
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __('connectors.ui.readiness.not_checked.body') }}
                </p>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                {{ $this->checkStoreSetupAction }}

                @if (filled($actionState['disabled_reason']))
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $actionState['disabled_reason'] }}
                    </span>
                @endif
            </div>
        </div>

        <div wire:loading.flex wire:target="checkStoreSetup" class="hidden items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>{{ __('connectors.ui.readiness.checking') }}</span>
        </div>
    </div>
</div>
