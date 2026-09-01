@php
    /** @var \App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount $this */
    $actionState = $this->storeSetupActionState();
    $baselineMessage = $this->storeSetupBaselineMessage;
    $state = $this->storeSetupState;
    $moduleVersion = $this->storeSetupModuleVersion;
    $applicationVersion = $this->storeSetupApplicationVersion;
    $phpVersion = $this->storeSetupPhpVersion;

    $requirements = app(\App\Support\Connectors\AdobePaaS\SafeSync\MagentoSafeSyncManifestReader::class)->requirements();
    $requirementsLines = array_values(array_filter([
        filled($requirements->phpConstraint)
            ? __('connectors.ui.readiness.developer.requirements.php', ['constraint' => $requirements->phpConstraint])
            : null,
        filled($requirements->magentoFrameworkConstraint)
            ? __('connectors.ui.readiness.developer.requirements.magento_framework', ['constraint' => $requirements->magentoFrameworkConstraint])
            : null,
        filled($requirements->magentoCatalogConstraint)
            ? __('connectors.ui.readiness.developer.requirements.magento_catalog', ['constraint' => $requirements->magentoCatalogConstraint])
            : null,
    ], fn (?string $value): bool => filled($value)));

    $record = $this->record;
    $accountName = is_object($record) && isset($record->name) ? (string) $record->name : null;
    $platformName = is_object($record) && method_exists($record, 'relationLoaded') && $record->relationLoaded('connectorDefinition') && $record->connectorDefinition
        ? (string) $record->connectorDefinition->name
        : null;

    $connectionEvidenceAt = null;
    if (is_object($record) && isset($record->last_successful_check_at) && $record->last_successful_check_at) {
        $connectionEvidenceAt = $record->last_successful_check_at;
    }

    $evidenceIso = $connectionEvidenceAt ? $connectionEvidenceAt->toIso8601String() : null;

    $stateLabel = match ($state) {
        'READY' => __('connectors.ui.readiness.developer.packet.state.ready'),
        'SETUP_REQUIRED' => __('connectors.ui.readiness.developer.packet.state.setup_required'),
        'UPDATE_REQUIRED' => __('connectors.ui.readiness.developer.packet.state.update_required'),
        'BASELINE_CONNECTION_FAILED' => __('connectors.ui.readiness.developer.packet.state.baseline_failure'),
        'READINESS_TEMPORARY_PROBLEM' => __('connectors.ui.readiness.developer.packet.state.temporary_problem'),
        'BASELINE_OK_READINESS_UNDETERMINED' => __('connectors.ui.readiness.developer.packet.state.readiness_undetermined'),
        default => __('connectors.ui.readiness.developer.packet.state.not_checked'),
    };

    $nextAction = match ($state) {
        'READY' => __('connectors.ui.readiness.developer.packet.next_action.ready'),
        'SETUP_REQUIRED' => __('connectors.ui.readiness.developer.packet.next_action.setup_required'),
        'UPDATE_REQUIRED' => __('connectors.ui.readiness.developer.packet.next_action.update_required'),
        'BASELINE_CONNECTION_FAILED' => __('connectors.ui.readiness.developer.packet.next_action.baseline_failure'),
        'READINESS_TEMPORARY_PROBLEM' => __('connectors.ui.readiness.developer.packet.next_action.temporary_problem'),
        'BASELINE_OK_READINESS_UNDETERMINED' => __('connectors.ui.readiness.developer.packet.next_action.readiness_undetermined'),
        default => __('connectors.ui.readiness.developer.packet.next_action.not_checked', [
            'action' => $this->storeSetupState === 'NOT_CHECKED'
                ? __('connectors.ui.readiness.check')
                : __('connectors.ui.readiness.check_again'),
        ]),
    };

    $unknown = __('connectors.ui.readiness.developer.packet.value.unknown');
    $diagnosticLines = array_values(array_filter([
        __('connectors.ui.readiness.developer.packet.diagnostics.operation', [
            'operation' => __('connectors.ui.readiness.developer.packet.diagnostics.operation.simple_product_write'),
        ]),
        __('connectors.ui.readiness.developer.packet.diagnostics.magento', [
            'value' => filled($applicationVersion) ? $applicationVersion : $unknown,
        ]),
        __('connectors.ui.readiness.developer.packet.diagnostics.php', [
            'value' => filled($phpVersion) ? $phpVersion : $unknown,
        ]),
        __('connectors.ui.readiness.developer.packet.diagnostics.extension', [
            'value' => filled($moduleVersion) ? $moduleVersion : $unknown,
        ]),
        in_array($state, ['BASELINE_CONNECTION_FAILED', 'READINESS_TEMPORARY_PROBLEM', 'BASELINE_OK_READINESS_UNDETERMINED'], true) && filled($baselineMessage)
            ? __('connectors.ui.readiness.developer.packet.diagnostics.failure', ['message' => $baselineMessage])
            : null,
    ], fn (?string $value): bool => filled($value)));

    $packetLines = array_values(array_filter([
        __('connectors.ui.readiness.developer.packet.title'),
        $platformName
            ? __('connectors.ui.readiness.developer.packet.platform', ['value' => $platformName])
            : null,
        $accountName
            ? __('connectors.ui.readiness.developer.packet.account', ['value' => $accountName])
            : null,
        $evidenceIso
            ? __('connectors.ui.readiness.developer.packet.connection_evidence_at', ['value' => $evidenceIso])
            : __('connectors.ui.readiness.developer.packet.connection_evidence_at', ['value' => $unknown]),
        __('connectors.ui.readiness.developer.packet.readiness_state', ['value' => $stateLabel]),
        __('connectors.ui.readiness.developer.packet.next_action', ['value' => $nextAction]),
        '',
        __('connectors.ui.readiness.developer.packet.diagnostics.title'),
        ...array_map(fn (string $line): string => '- '.$line, $diagnosticLines),
        '',
        __('connectors.ui.readiness.developer.packet.requirements.title'),
        ...array_map(fn (string $line): string => '- '.$line, $requirementsLines !== [] ? $requirementsLines : [$unknown]),
        '',
        __('connectors.ui.readiness.developer.packet.recheck', [
            'action' => $this->storeSetupState === 'NOT_CHECKED'
                ? __('connectors.ui.readiness.check')
                : __('connectors.ui.readiness.check_again'),
        ]),
    ], fn ($value): bool => is_string($value)));

    $packetText = implode("\n", $packetLines);

    $detailsParts = array_values(array_filter([
        filled($applicationVersion) ? __('connectors.ui.readiness.details.magento', ['version' => $applicationVersion]) : null,
        filled($phpVersion) ? __('connectors.ui.readiness.details.php', ['version' => $phpVersion]) : null,
        filled($moduleVersion) ? __('connectors.ui.readiness.details.extension', ['version' => $moduleVersion]) : null,
    ], fn (?string $value): bool => filled($value)));

    $detailsLine = $detailsParts !== [] ? implode(' · ', $detailsParts) : null;

    $containerClasses = match ($state) {
        'READY' => 'border-success-200 bg-success-50/70 dark:border-success-500/30 dark:bg-success-500/10',
        'SETUP_REQUIRED', 'UPDATE_REQUIRED' => 'border-warning-200 bg-warning-50/70 dark:border-warning-500/30 dark:bg-warning-500/10',
        'BASELINE_CONNECTION_FAILED' => 'border-danger-200 bg-danger-50/70 dark:border-danger-500/30 dark:bg-danger-500/10',
        'READINESS_TEMPORARY_PROBLEM' => 'border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5',
        'BASELINE_OK_READINESS_UNDETERMINED' => 'border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5',
        default => 'border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5',
    };

    $titleKey = match ($state) {
        'READY' => 'connectors.ui.readiness.ready.title',
        'SETUP_REQUIRED' => 'connectors.ui.readiness.setup_required.title',
        'UPDATE_REQUIRED' => 'connectors.ui.readiness.update_required.title',
        'BASELINE_CONNECTION_FAILED' => 'connectors.ui.readiness.baseline_failure.title',
        'READINESS_TEMPORARY_PROBLEM' => 'connectors.ui.readiness.temporary_problem.title',
        'BASELINE_OK_READINESS_UNDETERMINED' => 'connectors.ui.readiness.readiness_undetermined.title',
        default => null,
    };

    $bodyKey = match ($state) {
        'READY' => 'connectors.ui.readiness.ready.body',
        'SETUP_REQUIRED' => 'connectors.ui.readiness.setup_required.body',
        'UPDATE_REQUIRED' => 'connectors.ui.readiness.update_required.body',
        'READINESS_TEMPORARY_PROBLEM' => 'connectors.ui.readiness.temporary_problem.body',
        'BASELINE_OK_READINESS_UNDETERMINED' => 'connectors.ui.readiness.readiness_undetermined.body',
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
            @elseif ($state === 'BASELINE_CONNECTION_FAILED')
                <div class="space-y-3">
                    <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <p>{{ $baselineMessage }}</p>
                        <p>{{ __('connectors.ui.readiness.baseline_failure.guidance') }}</p>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        {{ __('connectors.ui.readiness.not_checked.body') }}
                    </p>
                </div>
            @endif

            @if ($state !== 'READY')
                <div class="flex flex-wrap items-center gap-3">
                    @if ($state === 'BASELINE_CONNECTION_FAILED')
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            :disabled="! $actionState['enabled']"
                            wire:click="mountAction('runConnectionCheck')"
                        >
                            {{ $actionState['label'] }}
                        </x-filament::button>
                    @elseif (in_array($state, ['SETUP_REQUIRED', 'UPDATE_REQUIRED'], true))
                        <x-filament.clipboard-copy-button
                            :text="$packetText"
                            :label="__('connectors.ui.readiness.developer.packet.copy')"
                            :copied-label="__('ui.clipboard.copied')"
                            color="primary"
                            icon="heroicon-o-clipboard-document"
                        />

                        {{ $this->checkStoreSetupAction }}
                    @else
                        {{ $this->checkStoreSetupAction }}
                    @endif

                    @if (filled($actionState['disabled_reason']) && ! $actionState['enabled'])
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $actionState['disabled_reason'] }}
                        </span>
                    @endif
                </div>
            @endif

            <details class="pt-1">
                <summary class="cursor-pointer select-none text-sm text-gray-700 dark:text-gray-300">
                    {{ __('connectors.ui.readiness.developer.summary') }}
                </summary>

                <div class="mt-3 space-y-3 rounded-lg border border-gray-200 bg-white/60 p-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    <p>{{ __('connectors.ui.readiness.developer.body') }}</p>

                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('connectors.ui.readiness.developer.packet.title') }}
                        </p>

                        <x-filament.clipboard-copy-button
                            :text="$packetText"
                            :label="__('connectors.ui.readiness.developer.packet.copy')"
                            :copied-label="__('ui.clipboard.copied')"
                            icon="heroicon-o-clipboard-document"
                        />
                    </div>

                    <pre class="whitespace-pre-wrap rounded-md border border-gray-200 bg-white p-3 text-xs leading-5 text-gray-900 dark:border-white/10 dark:bg-black/20 dark:text-gray-100">{{ $packetText }}</pre>

                    @if ($requirementsLines !== [])
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ __('connectors.ui.readiness.developer.requirements.title') }}
                            </p>
                            <ul class="list-disc space-y-1 pl-5">
                                @foreach ($requirementsLines as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </details>
        </div>

        <div wire:loading.flex class="hidden items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>{{ __('connectors.ui.readiness.checking') }}</span>
        </div>
    </div>
</div>
