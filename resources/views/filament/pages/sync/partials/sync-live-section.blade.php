@if ($liveSectionVisible)
  <x-filament::section data-testid="sync-live-section" data-page-state="{{ $livePageState }}">
    <x-slot name="heading">
      {{ __('sync_live.section.title') }}
    </x-slot>

    @if ($liveConfigurationChangedSinceRun)
      <p class="mb-3 text-sm text-warning-600 dark:text-warning-400" data-testid="sync-live-configuration-changed">
        {{ __('sync_live.status.global_configuration_changed') }}
      </p>
    @endif

    @if (! $liveHasPreviewEvidence && in_array($livePageState, ['preview_prerequisite_missing', 'support_not_enabled', 'ready_to_transfer', 'configuration_not_ready', 'active_run_blocking', 'completed', 'failed'], true))
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-preview-prerequisite">
        {{ __('sync_live.preview_prerequisite.missing') }}
      </p>
    @elseif ($liveHasPreviewEvidence && in_array($livePageState, ['preview_prerequisite_missing', 'support_not_enabled', 'ready_to_transfer', 'configuration_not_ready', 'active_run_blocking'], true))
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-preview-prerequisite">
        {{ __('sync_live.preview_prerequisite.met') }}
      </p>
    @endif

    @switch($livePageState)
      @case('configuration_absent')
        @include('filament.pages.sync.partials.sync-preview-setup-notice', [
          'accountId' => $accountId,
          'canManageSetup' => $canManageSetup,
          'testId' => 'sync-live-setup-required',
        ])
        @break

      @case('account_unavailable')
        <p class="text-sm text-warning-600 dark:text-warning-400" data-testid="sync-live-account-unavailable">
          {{ __('sync_data_setup.adobe_products_export.account_unavailable') }}
        </p>
        @break

      @case('configuration_paused')
        <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-configuration-paused">
          {{ __('sync_data_setup.adobe_products_export.configuration_paused') }}
        </p>
        @break

      @case('export_unavailable')
        <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-export-unavailable">
          {{ __('sync_live.states.export_unavailable') }}
        </p>
        @break

      @case('configuration_not_ready')
        @include('filament.pages.sync.partials.sync-preview-setup-notice', [
          'accountId' => $accountId,
          'canManageSetup' => $canManageSetup,
          'testId' => 'sync-live-configuration-not-ready',
        ])
        @break

      @case('preview_prerequisite_missing')
        <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-preview-required">
          {{ __('sync_live.states.preview_prerequisite_missing') }}
        </p>
        @break

      @case('support_not_enabled')
        <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-support-not-enabled">
          {{ __('sync_live.states.support_not_enabled') }}
        </p>
        @break

      @case('active_run_blocking')
        <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-active-run-blocking">
          {{ __('sync_live.states.active_run_blocking') }}
        </p>
        @break

      @case('ready_to_transfer')
        <div class="space-y-3">
          <p class="text-sm text-gray-700 dark:text-gray-200">
            {{ __('sync_live.states.ready_to_transfer') }}
          </p>
          <x-filament::button
            wire:click="startLive"
            wire:confirm="{{ __('sync_live.confirmation.message') }}"
            color="danger"
            data-testid="sync-live-start"
          >
            {{ __('sync_live.actions.start') }}
          </x-filament::button>
        </div>
        @break

      @case('queued')
      @case('running')
        <div class="space-y-2">
          <p class="text-sm font-medium text-gray-800 dark:text-gray-100" data-testid="sync-live-lifecycle">
            {{ $liveLifecycleLabel }}
          </p>
          @if ($liveProcessedProductCount !== null && $liveProcessedProductCount > 0)
            <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-processed-count">
              {{ __('sync_live.lifecycle.processed_products', ['count' => $liveProcessedProductCount]) }}
            </p>
          @endif
        </div>
        @break

      @case('failed')
        @if ($liveCurrentSetupRequired)
          @include('filament.pages.sync.partials.sync-preview-setup-notice', [
            'accountId' => $accountId,
            'canManageSetup' => $canManageSetup,
            'testId' => 'sync-live-current-setup-required',
          ])
        @endif
        <div class="space-y-3">
          <p class="text-sm text-danger-600 dark:text-danger-400" data-testid="sync-live-failed">
            {{ __('sync_live.lifecycle.failed') }}
          </p>
          @if ($canStartLive)
            <x-filament::button
              wire:click="startLive"
              wire:confirm="{{ __('sync_live.confirmation.message') }}"
              color="primary"
              data-testid="sync-live-retry"
            >
              {{ __('sync_live.actions.retry') }}
            </x-filament::button>
          @endif
        </div>
        @break

      @case('completed')
        @if ($liveCurrentSetupRequired)
          @include('filament.pages.sync.partials.sync-preview-setup-notice', [
            'accountId' => $accountId,
            'canManageSetup' => $canManageSetup,
            'testId' => 'sync-live-current-setup-required',
          ])
        @endif
        <div class="space-y-3" data-testid="sync-live-completed-summary">
          <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
            {{ $liveResultAttentionStatement }}
          </p>
          <div class="grid gap-2 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-2 lg:grid-cols-4">
            <p>{{ __('sync_live.results.synchronized', ['count' => $liveSynchronizedCount]) }}</p>
            <p>{{ __('sync_live.results.not_applied', ['count' => $liveNotAppliedCount]) }}</p>
            <p>{{ __('sync_live.results.partial', ['count' => $livePartialCount]) }}</p>
            <p>{{ __('sync_live.results.ambiguous', ['count' => $liveAmbiguousCount]) }}</p>
          </div>
          @if ($liveCompletedAtLabel)
            <p class="text-xs text-gray-500 dark:text-gray-400">
              {{ __('sync_live.results.completed_at', ['datetime' => $liveCompletedAtLabel]) }}
            </p>
          @endif
          @if ($canStartLive)
            <x-filament::button
              wire:click="startLive"
              wire:confirm="{{ __('sync_live.confirmation.message') }}"
              color="gray"
              data-testid="sync-live-rerun"
            >
              {{ __('sync_live.actions.rerun') }}
            </x-filament::button>
          @endif
        </div>
        @break
    @endswitch
  </x-filament::section>
@endif
