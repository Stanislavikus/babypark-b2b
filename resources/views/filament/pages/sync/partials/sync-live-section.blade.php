@if ($liveSectionVisible)
  <x-filament::section
    data-testid="sync-live-section"
    data-lifecycle-state="{{ $liveLifecycleState }}"
    data-support-available="{{ $liveSupportAvailable ? '1' : '0' }}"
  >
    <x-slot name="heading">
      {{ __('sync_live.section.title') }}
    </x-slot>

    @if ($liveConfigurationChangedSinceRun)
      <p class="mb-3 text-sm text-warning-600 dark:text-warning-400" data-testid="sync-live-configuration-changed">
        {{ __('sync_live.status.global_configuration_changed') }}
      </p>
    @endif

    @if ($liveSetupBarrier)
      @switch($liveSetupBarrier)
        @case('configuration_absent')
          @include('filament.pages.sync.partials.sync-preview-setup-notice', [
            'accountId' => $accountId,
            'canManageSetup' => $canManageSetup,
            'testId' => 'sync-live-setup-required',
          ])
          @break
        @case('account_unavailable')
          <p class="mb-3 text-sm text-warning-600 dark:text-warning-400" data-testid="sync-live-account-unavailable">
            {{ __('sync_data_setup.adobe_products_export.account_unavailable') }}
          </p>
          @break
        @case('configuration_paused')
          <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-configuration-paused">
            {{ __('sync_data_setup.adobe_products_export.configuration_paused') }}
          </p>
          @break
        @case('export_unavailable')
          <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-export-unavailable">
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
      @endswitch
    @endif

    @if ($liveLifecycleState === 'queued' || $liveLifecycleState === 'running')
      <div class="mb-3 space-y-2">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100" data-testid="sync-live-lifecycle">
          {{ $liveLifecycleLabel }}
        </p>
        @if ($liveProcessedProductCount !== null && $liveProcessedProductCount > 0)
          <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-processed-count">
            {{ __('sync_live.lifecycle.processed_products', ['count' => $liveProcessedProductCount]) }}
          </p>
        @endif
      </div>
    @elseif ($liveLifecycleState === 'failed')
      @if ($liveCurrentSetupRequired)
        @include('filament.pages.sync.partials.sync-preview-setup-notice', [
          'accountId' => $accountId,
          'canManageSetup' => $canManageSetup,
          'testId' => 'sync-live-current-setup-required',
        ])
      @endif
      <p class="mb-3 text-sm text-danger-600 dark:text-danger-400" data-testid="sync-live-failed">
        {{ __('sync_live.lifecycle.failed') }}
      </p>
    @elseif ($liveLifecycleState === 'completed' && ! $liveResultPresentationTrusted)
      <p class="mb-3 text-sm text-danger-600 dark:text-danger-400" data-testid="sync-live-untrusted-result">
        {{ __('sync_live.results.untrusted') }}
      </p>
    @elseif ($liveLifecycleState === 'completed' && $liveResultPresentationTrusted)
      <div class="mb-4 space-y-3" data-testid="sync-live-completed-summary">
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
      </div>
    @endif

    @if ($liveActivePreviewBlocking && $liveLifecycleState === 'none')
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-active-preview-blocking">
        {{ __('sync_live.states.active_preview_blocking') }}
      </p>
    @elseif ($liveBlockedByActiveRun && $liveLifecycleState === 'none' && ! $liveActivePreviewBlocking)
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-active-run-blocking">
        {{ __('sync_live.states.active_run_blocking') }}
      </p>
    @endif

    @if (! $livePreviewPrerequisiteSatisfied && $liveLifecycleState === 'none')
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-preview-required">
        {{ __('sync_live.states.preview_prerequisite_missing') }}
      </p>
    @elseif ($livePreviewPrerequisiteSatisfied && ! $liveSupportAvailable && $liveLifecycleState === 'none')
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-preview-prerequisite">
        {{ __('sync_live.preview_prerequisite.met') }}
      </p>
    @endif

    @if (! $liveSupportAvailable && ($liveLifecycleState === 'none' || $liveLifecycleState === 'completed' || $liveLifecycleState === 'failed'))
      <p class="mb-3 text-sm text-gray-700 dark:text-gray-200" data-testid="sync-live-support-not-enabled">
        {{ __('sync_live.states.support_not_enabled') }}
      </p>
    @endif

    @if ($canStartLive)
      <div class="mb-4 space-y-3" data-testid="sync-live-ready-to-transfer">
        <p class="text-sm text-gray-700 dark:text-gray-200">
          {{ __('sync_live.states.ready_to_transfer') }}
        </p>
        @if ($livePreviewBlockedCount !== null && $livePreviewBlockedCount > 0)
          <p class="text-sm text-gray-700 dark:text-gray-200">
            {{ __('sync_live.confirmation.blocked_products_notice', ['count' => $livePreviewBlockedCount]) }}
          </p>
        @endif
        <x-filament::button
          wire:click="startLive"
          wire:confirm="{{ __('sync_live.confirmation.message') }}"
          color="danger"
          data-testid="sync-live-start"
        >
          {{ __('sync_live.actions.start') }}
        </x-filament::button>
      </div>
    @endif

    @if ($liveLifecycleState === 'completed' && $liveResultPresentationTrusted)
      <x-filament::section>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div class="flex flex-wrap gap-2">
            @foreach ([
              'needs_attention' => __('sync_live.filters.needs_attention'),
              'not_applied' => __('sync_live.filters.not_applied'),
              'partial' => __('sync_live.filters.partial'),
              'ambiguous' => __('sync_live.filters.ambiguous'),
              'synchronized' => __('sync_live.filters.synchronized'),
              'all' => __('sync_live.filters.all'),
            ] as $value => $label)
              <button
                type="button"
                wire:click="$set('liveWorklistFilter', '{{ $value }}')"
                @class([
                  'rounded-md px-3 py-1.5 text-sm',
                  'bg-primary-600 text-white' => $liveWorklistFilter === $value,
                  'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => $liveWorklistFilter !== $value,
                ])
                data-testid="sync-live-filter-{{ $value }}"
              >
                {{ $label }}
              </button>
            @endforeach
          </div>
          <div class="w-full sm:max-w-xs">
            <input
              type="search"
              wire:model.live.debounce.300ms="liveWorklistSearch"
              placeholder="{{ __('sync_live.worklist.search_placeholder') }}"
              class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
              data-testid="sync-live-worklist-search"
            />
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full min-w-full text-sm" data-testid="sync-live-worklist">
            <thead>
              <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                <th class="px-3 py-2">{{ __('sync_live.worklist.columns.product') }}</th>
                <th class="px-3 py-2">{{ __('sync_live.worklist.columns.outcome') }}</th>
                <th class="px-3 py-2">{{ __('sync_live.worklist.columns.guidance') }}</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($liveWorklistRows as $row)
                <tr class="border-b border-gray-100 align-top dark:border-gray-800" data-testid="sync-live-worklist-row">
                  <td class="px-3 py-3">{!! $row['identity_html'] !!}</td>
                  <td class="px-3 py-3">
                    <x-filament::badge :color="$row['outcome_color']">
                      {{ $row['outcome_label'] }}
                    </x-filament::badge>
                  </td>
                  <td class="px-3 py-3">
                    @if (! empty($row['guidance']))
                      <p>{{ $row['guidance'] }}</p>
                    @endif
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="3" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                    {{ __('sync_live.worklist.empty') }}
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </x-filament::section>

      @if ($canStartLive)
        <div class="mt-3">
          <x-filament::button
            wire:click="startLive"
            wire:confirm="{{ __('sync_live.confirmation.message') }}"
            color="gray"
            data-testid="sync-live-rerun"
          >
            {{ __('sync_live.actions.rerun') }}
          </x-filament::button>
        </div>
      @endif
    @endif
  </x-filament::section>
@endif
