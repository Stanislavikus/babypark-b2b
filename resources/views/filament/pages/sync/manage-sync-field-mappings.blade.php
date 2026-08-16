<x-filament-panels::page>
  <div class="space-y-4">
    @if (! $discoveryAvailable)
      <x-filament::section>
        <p class="text-sm text-gray-700 dark:text-gray-200">
          {{ __('sync_mappings.no_discovery_notice', ['platform' => $platformName]) }}
        </p>
      </x-filament::section>
    @endif

    <x-filament.data-list-toolbar
      :filters-count="$statusFilter !== 'all' ? 1 : 0"
      :has-filters="true"
      :filters-label="__('sync_mappings.filters.label')"
      panel-id="sync-mappings-toolbar-panel"
    >
      <x-slot name="search">
        <label for="sync-mappings-search" class="sr-only">{{ __('sync_mappings.search_label') }}</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="sync-mappings-search"
            type="search"
            placeholder="{{ __('sync_mappings.search_placeholder') }}"
            aria-label="{{ __('sync_mappings.search_label') }}"
            wire:model.live.debounce.300ms="search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="actions">
        @if ($progressSummary)
          <span class="text-sm text-gray-600 dark:text-gray-300" data-testid="sync-mappings-progress">
            {{ $progressSummary }}
          </span>
        @endif

        @if ($availableFieldsUrl)
          <x-filament::button
            tag="a"
            :href="$availableFieldsUrl"
            color="gray"
            icon="heroicon-m-list-bullet"
            data-testid="sync-mappings-available-fields-link"
          >
            {{ __('sync_mappings.available_fields_action', ['platform' => $platformName]) }}
          </x-filament::button>
        @endif
      </x-slot>

      <x-slot name="panel">
        <div class="space-y-4 p-4" data-testid="sync-mappings-filter-panel">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('sync_mappings.filters.label') }}</h3>
          <div>
            <label for="sync-mappings-status-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
              {{ __('sync_mappings.filters.status') }}
            </label>
            <x-filament::input.wrapper>
              <x-filament::input.select id="sync-mappings-status-filter" wire:model.live="statusFilter">
                <option value="all">{{ __('sync_mappings.filters.all') }}</option>
                <option value="needs_attention">{{ __('sync_mappings.filters.needs_attention') }}</option>
                <option value="mapped">{{ __('sync_mappings.filters.mapped') }}</option>
                <option value="unmapped">{{ __('sync_mappings.filters.unmapped') }}</option>
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
              {{ __('sync_mappings.columns.catalog_field') }}
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {{ __('sync_mappings.columns.platform_field', ['platform' => $platformName]) }}
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {{ __('sync_mappings.columns.status') }}
            </th>
            @if ($canMutate && $discoveryAvailable)
              <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('sync_mappings.columns.actions') }}
              </th>
            @endif
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
          @forelse ($displayRows as $row)
            <tr data-testid="sync-mapping-row" data-state="{{ $row['semantic_state'] }}">
              <td class="px-4 py-3 text-sm text-gray-950 dark:text-white">
                {{ $row['internal_label'] }}
              </td>
              <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                {{ $row['external_label'] ?? __('sync_mappings.external_field_empty') }}
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
              @if ($canMutate && $discoveryAvailable)
                <td class="px-4 py-3 text-right text-sm">
                  <div class="flex flex-wrap justify-end gap-2">
                    @if ($row['semantic_state'] === 'suggested')
                      <x-filament::button
                        size="xs"
                        color="primary"
                        wire:click="confirmMapping(@js($row['field_binding_id']), @js($row['suggested_external_field_key']))"
                        data-testid="sync-mapping-confirm"
                      >
                        {{ __('sync_mappings.actions.confirm') }}
                      </x-filament::button>
                    @endif

                    @if ($row['semantic_state'] === 'mapped' || $row['semantic_state'] === 'needs_attention')
                      <x-filament::button
                        size="xs"
                        color="gray"
                        wire:click="mountAction('changeMapping', @js(['fieldBindingId' => $row['field_binding_id'], 'externalFieldKey' => $row['existing_external_field_key']]))"
                        data-testid="sync-mapping-change"
                      >
                        {{ __('sync_mappings.actions.change') }}
                      </x-filament::button>

                      <x-filament::button
                        size="xs"
                        color="danger"
                        wire:click="removeMapping(@js($row['field_binding_id']), @js($row['existing_external_field_key']))"
                        data-testid="sync-mapping-remove"
                      >
                        {{ __('sync_mappings.actions.remove') }}
                      </x-filament::button>
                    @endif

                    @if ($row['semantic_state'] === 'unmapped')
                      <x-filament::button
                        size="xs"
                        color="gray"
                        wire:click="mountAction('changeMapping', @js(['fieldBindingId' => $row['field_binding_id'], 'externalFieldKey' => '' ]))"
                        data-testid="sync-mapping-choose"
                      >
                        {{ __('sync_mappings.actions.choose') }}
                      </x-filament::button>
                    @endif
                  </div>
                </td>
              @endif
            </tr>
          @empty
            <tr>
              <td colspan="{{ $canMutate && $discoveryAvailable ? 4 : 3 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('sync_mappings.empty') }}
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <x-filament-actions::modals />
</x-filament-panels::page>
