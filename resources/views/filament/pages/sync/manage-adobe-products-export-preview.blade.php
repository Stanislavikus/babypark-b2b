<x-filament-panels::page
  data-testid="sync-preview-page"
  data-page-state="{{ $pageState }}"
>
  <div class="space-y-4" @if ($pollActive) wire:poll.5s="refreshPresentation" @endif>
    <x-filament::section>
      <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('sync_preview.page.context', [
          'platform' => $platformName,
          'account' => $accountName,
        ]) }}
      </p>
    </x-filament::section>

    @if ($configurationChangedSinceRun)
      <x-filament::section>
        <p class="text-sm text-warning-600 dark:text-warning-400" data-testid="sync-preview-configuration-changed">
          {{ __('sync_preview.status.global_configuration_changed') }}
        </p>
      </x-filament::section>
    @endif

    @switch($pageState)
      @case('configuration_absent')
        <x-filament::section>
          <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-preview-setup-required">
            {{ __('sync_preview.states.setup_required') }}
          </p>
          @if ($canManageSetup)
            <div class="mt-3">
              <x-filament::button
                tag="a"
                :href="\App\Filament\Pages\Sync\ManageAdobeProductsExportSetup::getUrl(['account' => $accountId])"
                data-testid="sync-preview-setup-action"
              >
                {{ __('sync_data_setup.page.open_setup') }}
              </x-filament::button>
            </div>
          @else
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
              {{ __('sync_preview.states.setup_permission_required') }}
            </p>
          @endif
        </x-filament::section>
        @break

      @case('account_unavailable')
        <x-filament::section>
          <p class="text-sm text-warning-600 dark:text-warning-400" data-testid="sync-preview-account-unavailable">
            {{ __('sync_data_setup.adobe_products_export.account_unavailable') }}
          </p>
        </x-filament::section>
        @break

      @case('configuration_paused')
        <x-filament::section>
          <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-preview-configuration-paused">
            {{ __('sync_data_setup.adobe_products_export.configuration_paused') }}
          </p>
        </x-filament::section>
        @break

      @case('export_unavailable')
        <x-filament::section>
          <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="sync-preview-export-unavailable">
            {{ __('sync_preview.states.export_unavailable') }}
          </p>
        </x-filament::section>
        @break

      @case('ready_to_preview')
        <x-filament::section>
          @if ($canStartPreview)
            <x-filament::button
              wire:click="startPreview"
              color="primary"
              data-testid="sync-preview-start"
            >
              {{ __('sync_preview.actions.start') }}
            </x-filament::button>
          @endif
        </x-filament::section>
        @break

      @case('queued')
      @case('running')
        <x-filament::section>
          <p class="text-sm font-medium text-gray-800 dark:text-gray-100" data-testid="sync-preview-lifecycle">
            {{ $lifecycleLabel }}
          </p>
        </x-filament::section>
        @break

      @case('failed')
        <x-filament::section>
          <p class="text-sm text-danger-600 dark:text-danger-400" data-testid="sync-preview-failed">
            {{ __('sync_preview.lifecycle.failed') }}
          </p>
          @if ($canStartPreview)
            <div class="mt-3">
              <x-filament::button wire:click="startPreview" color="primary" data-testid="sync-preview-retry">
                {{ __('sync_preview.actions.retry') }}
              </x-filament::button>
            </div>
          @endif
        </x-filament::section>
        @break

      @case('completed')
        <x-filament::section>
          <div class="space-y-3" data-testid="sync-preview-completed-summary">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
              {{ $resultAttentionStatement }}
            </p>
            <div class="grid gap-2 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-3">
              <p>{{ __('sync_preview.results.ready', ['count' => $readyCount]) }}</p>
              <p>{{ __('sync_preview.results.warning', ['count' => $warningCount]) }}</p>
              <p>{{ __('sync_preview.results.blocked', ['count' => $blockedCount]) }}</p>
            </div>
            @if ($completedAtLabel)
              <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('sync_preview.results.completed_at', ['datetime' => $completedAtLabel]) }}
              </p>
            @endif
            @if ($canStartPreview)
              <x-filament::button wire:click="startPreview" color="gray" data-testid="sync-preview-rerun">
                {{ __('sync_preview.actions.rerun') }}
              </x-filament::button>
            @endif
          </div>
        </x-filament::section>

        <x-filament::section>
          <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-wrap gap-2">
              @foreach ([
                'needs_attention' => __('sync_preview.filters.needs_attention'),
                'blocked' => __('sync_preview.filters.blocked'),
                'warning' => __('sync_preview.filters.warning'),
                'ready' => __('sync_preview.filters.ready'),
                'all' => __('sync_preview.filters.all'),
              ] as $value => $label)
                <button
                  type="button"
                  wire:click="$set('worklistFilter', '{{ $value }}')"
                  @class([
                    'rounded-md px-3 py-1.5 text-sm',
                    'bg-primary-600 text-white' => $worklistFilter === $value,
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => $worklistFilter !== $value,
                  ])
                  data-testid="sync-preview-filter-{{ $value }}"
                >
                  {{ $label }}
                </button>
              @endforeach
            </div>
            <div class="w-full sm:max-w-xs">
              <input
                type="search"
                wire:model.live.debounce.300ms="worklistSearch"
                placeholder="{{ __('sync_preview.worklist.search_placeholder') }}"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                data-testid="sync-preview-worklist-search"
              />
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full min-w-full text-sm" data-testid="sync-preview-worklist">
              <thead>
                <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                  <th class="px-3 py-2">{{ __('sync_preview.worklist.columns.product') }}</th>
                  <th class="px-3 py-2">{{ __('sync_preview.worklist.columns.outcome') }}</th>
                  <th class="px-3 py-2">{{ __('sync_preview.worklist.columns.attention') }}</th>
                  <th class="px-3 py-2">{{ __('sync_preview.worklist.columns.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($worklistRows as $row)
                  <tr class="border-b border-gray-100 align-top dark:border-gray-800" data-testid="sync-preview-worklist-row-{{ $row['product_id'] }}">
                    <td class="px-3 py-3">{!! $row['identity_html'] !!}</td>
                    <td class="px-3 py-3">
                      <x-filament::badge :color="$row['outcome_color']">
                        {{ $row['outcome_label'] }}
                      </x-filament::badge>
                    </td>
                    <td class="px-3 py-3">
                      <div class="space-y-3">
                        @foreach ($row['findings'] as $finding)
                          <div class="space-y-1" data-testid="sync-preview-finding">
                            @if (! empty($finding['variant_context']))
                              <p class="text-xs text-gray-500 dark:text-gray-400">{{ $finding['variant_context'] }}</p>
                            @endif
                            @if (! empty($finding['field_context']))
                              <p class="text-xs text-gray-500 dark:text-gray-400">{{ $finding['field_context'] }}</p>
                            @endif
                            <p>{{ $finding['summary'] }}</p>
                          </div>
                        @endforeach
                      </div>
                    </td>
                    <td class="px-3 py-3">
                      <div class="space-y-3">
                        @foreach ($row['findings'] as $finding)
                          <div class="space-y-1">
                            @foreach ($finding['destinations'] as $destination)
                              @if (! empty($destination['action_url']) && ! empty($destination['action_label']))
                                <x-filament::button
                                  tag="a"
                                  size="xs"
                                  :href="$destination['action_url']"
                                  color="gray"
                                >
                                  {{ $destination['action_label'] }}
                                </x-filament::button>
                              @elseif (! empty($destination['status_message']))
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $destination['status_message'] }}</p>
                              @endif
                            @endforeach
                          </div>
                        @endforeach
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="4" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                      {{ __('sync_preview.worklist.empty') }}
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </x-filament::section>
        @break
    @endswitch
  </div>
</x-filament-panels::page>
