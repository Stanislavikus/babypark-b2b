<x-filament-panels::page>
  <div class="space-y-4">
    <div class="space-y-1">
      <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ $internalFieldLabel }} → {{ $externalFieldLabel }}
      </p>
      <p class="text-xs text-gray-500 dark:text-gray-400">
        {{ $platformName }} · {{ $accountName }}
      </p>
    </div>

    @if (! $externalChoicesResolvable)
      <x-filament::section>
        <p class="text-sm text-gray-700 dark:text-gray-200">
          {{ __('sync_option_mappings.no_external_choices_notice', ['platform' => $platformName]) }}
        </p>
      </x-filament::section>
    @endif

    <x-filament.data-list-toolbar
      :filters-count="$statusFilter !== 'all' ? 1 : 0"
      :has-filters="true"
      :filters-label="__('sync_option_mappings.filters.label')"
      panel-id="sync-option-mappings-toolbar-panel"
    >
      <x-slot name="search">
        <label for="sync-option-mappings-search" class="sr-only">{{ __('sync_option_mappings.search_label') }}</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="sync-option-mappings-search"
            type="search"
            placeholder="{{ __('sync_option_mappings.search_placeholder') }}"
            aria-label="{{ __('sync_option_mappings.search_label') }}"
            wire:model.live.debounce.300ms="search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="panel">
        <div class="space-y-4 p-4" data-testid="sync-option-mappings-filter-panel">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('sync_option_mappings.filters.label') }}</h3>
          <div>
            <label for="sync-option-mappings-status-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
              {{ __('sync_option_mappings.filters.status') }}
            </label>
            <x-filament::input.wrapper>
              <x-filament::input.select id="sync-option-mappings-status-filter" wire:model.live="statusFilter">
                <option value="all">{{ __('sync_option_mappings.filters.all') }}</option>
                <option value="needs_attention">{{ __('sync_option_mappings.filters.needs_attention') }}</option>
                <option value="mapped">{{ __('sync_option_mappings.filters.mapped') }}</option>
                <option value="unmapped">{{ __('sync_option_mappings.filters.unmapped') }}</option>
              </x-filament::input.select>
            </x-filament::input.wrapper>
          </div>
        </div>
      </x-slot>
    </x-filament.data-list-toolbar>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
      <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
        <thead class="bg-gray-50 dark:bg-white/5">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {{ __('sync_option_mappings.columns.catalog_value') }}
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {{ $platformName }}
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {{ __('sync_option_mappings.columns.status') }}
            </th>
            @if ($canMutate)
              <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('sync_option_mappings.columns.actions') }}
              </th>
            @endif
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
          @forelse ($displayRows as $row)
            <tr data-testid="sync-option-mapping-row" data-state="{{ $row['semantic_state'] }}">
              <td class="px-4 py-3 text-sm text-gray-950 dark:text-white">
                {{ $row['internal_label'] }}
              </td>
              <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                {{ $row['external_label'] }}
              </td>
              <td class="px-4 py-3 text-sm">
                <span class="inline-flex items-center gap-1 text-gray-700 dark:text-gray-200">
                  @if ($row['status_icon'] === 'check')
                    <x-heroicon-m-check class="h-4 w-4 text-success-600" />
                  @elseif ($row['status_icon'] === 'warning')
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4 text-warning-600" />
                  @endif
                  <span>{{ $row['status_label'] }}</span>
                </span>
              </td>
              @if ($canMutate)
                <td class="px-4 py-3 text-right text-sm">
                  <div class="flex flex-wrap justify-end gap-2">
                    @if ($externalChoicesResolvable)
                      @if ($row['semantic_state'] === 'unmapped')
                        <x-filament::button
                          size="xs"
                          color="primary"
                          wire:click="mountAction('changeMapping', {{ \Illuminate\Support\Js::from(['internalOptionKey' => $row['internal_option_key'], 'externalOptionValue' => '']) }})"
                          data-testid="sync-option-mapping-confirm"
                        >
                          {{ __('sync_option_mappings.actions.confirm') }}
                        </x-filament::button>
                      @endif

                      @if ($row['semantic_state'] === 'mapped' || $row['semantic_state'] === 'external_value_unavailable')
                        <x-filament::button
                          size="xs"
                          color="gray"
                          wire:click="mountAction('changeMapping', {{ \Illuminate\Support\Js::from(['internalOptionKey' => $row['internal_option_key'], 'externalOptionValue' => $row['existing_external_option_value']]) }})"
                          data-testid="sync-option-mapping-change"
                        >
                          {{ __('sync_option_mappings.actions.change') }}
                        </x-filament::button>
                      @endif
                    @endif

                    @if ($row['existing_external_option_value'] !== null && $row['existing_external_option_value'] !== '')
                      <x-filament::button
                        size="xs"
                        color="danger"
                        wire:click="removeMapping({{ \Illuminate\Support\Js::from($row['internal_option_key']) }}, {{ \Illuminate\Support\Js::from($row['existing_external_option_value']) }})"
                        data-testid="sync-option-mapping-remove"
                      >
                        {{ __('sync_option_mappings.actions.remove') }}
                      </x-filament::button>
                    @endif
                  </div>
                </td>
              @endif
            </tr>
          @empty
            <tr>
              <td colspan="{{ $canMutate ? 4 : 3 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('sync_option_mappings.empty') }}
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if ($staleRows !== [])
      <div class="space-y-3">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
          {{ __('sync_option_mappings.stale.section_title') }}
        </h2>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  {{ __('sync_option_mappings.stale.internal_column') }}
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  {{ $platformName }}
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  {{ __('sync_option_mappings.columns.status') }}
                </th>
                @if ($canMutate)
                  <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('sync_option_mappings.columns.actions') }}
                  </th>
                @endif
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
              @foreach ($staleRows as $row)
                <tr data-testid="sync-option-mapping-stale-row">
                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ $row['internal_unavailable_label'] }}
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ $row['external_label'] }}
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ $row['status_label'] }}
                  </td>
                  @if ($canMutate)
                    <td class="px-4 py-3 text-right text-sm">
                      <x-filament::button
                        size="xs"
                        color="danger"
                        wire:click="removeStaleCorrespondence({{ \Illuminate\Support\Js::from($row['field_option_mapping_id']) }})"
                        data-testid="sync-option-mapping-stale-remove"
                      >
                        {{ __('sync_option_mappings.actions.remove') }}
                      </x-filament::button>
                    </td>
                  @endif
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif
  </div>

  <x-filament-actions::modals />
</x-filament-panels::page>
