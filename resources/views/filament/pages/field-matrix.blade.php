<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament.data-list-toolbar
      :filters-count="$this->activeFiltersCount()"
      :has-filters="true"
      :filters-label="__('field_matrix.filters')"
      panel-id="field-matrix-toolbar-panel"
    >
      <x-slot name="search">
        <label for="field-matrix-search" class="sr-only">{{ __('field_matrix.search_label') }}</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="field-matrix-search"
            type="search"
            placeholder="{{ __('field_matrix.search_placeholder') }}"
            aria-label="{{ __('field_matrix.search_label') }}"
            wire:model.live.debounce.300ms="data.search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="actions">
        <x-filament::button
          color="gray"
          icon="heroicon-m-view-columns"
          data-testid="compare-channels-trigger"
          x-on:click="panelFocus = 'compare'; $dispatch('open-modal', { id: 'field-matrix-toolbar-panel' })"
        >
          <span class="flex items-center gap-2">
            <span>{{ __('field_matrix.compare_channels') }}</span>
            @if ($this->selectedComparisonColumnCount() > 0)
              <x-filament::badge color="primary" size="sm">
                {{ $this->selectedComparisonColumnCount() }}
              </x-filament::badge>
            @endif
          </span>
        </x-filament::button>
      </x-slot>

      <x-slot name="panel">
        <div
          class="max-h-[70vh] space-y-6 overflow-y-auto p-4"
          data-testid="field-matrix-toolbar-panel"
        >
          <div
            x-show="panelFocus === 'filters' || panelFocus === 'all'"
            x-cloak
            class="space-y-4"
            data-testid="field-matrix-panel-filters"
          >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('field_matrix.filters') }}</h3>

            <div>
              <label for="field-matrix-field-group" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('field_matrix.filter_group') }}
              </label>
              <x-filament::input.wrapper>
                <x-filament::input.select
                  id="field-matrix-field-group"
                  wire:model.live="data.fieldGroup"
                >
                  <option value="">{{ __('field_matrix.filter_group_all') }}</option>
                  @foreach ($this->fieldGroupOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </x-filament::input.select>
              </x-filament::input.wrapper>
            </div>

            <div>
              <label for="field-matrix-binding-strategy" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('field_matrix.filter_binding') }}
              </label>
              <x-filament::input.wrapper>
                <x-filament::input.select
                  id="field-matrix-binding-strategy"
                  wire:model.live="data.bindingStrategy"
                >
                  <option value="">{{ __('field_matrix.filter_binding_all') }}</option>
                  @foreach ($this->bindingStrategyOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </x-filament::input.select>
              </x-filament::input.wrapper>
            </div>

            <div>
              <label for="field-matrix-scope" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('field_matrix.filter_scope') }}
              </label>
              <x-filament::input.wrapper>
                <x-filament::input.select
                  id="field-matrix-scope"
                  wire:model.live="data.scope"
                >
                  <option value="">{{ __('field_matrix.filter_scope_all') }}</option>
                  @foreach ($this->scopeOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                  @endforeach
                </x-filament::input.select>
              </x-filament::input.wrapper>
            </div>

            @if ($this->activeFiltersCount() > 0)
              <button
                type="button"
                wire:click="clearAllFilters"
                class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
              >
                {{ __('field_matrix.clear_all') }}
              </button>
            @endif
          </div>

          <div
            x-show="panelFocus === 'compare' || panelFocus === 'all'"
            x-cloak
            class="space-y-3"
            data-testid="field-matrix-panel-compare"
          >
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('field_matrix.compare_channels') }}</h3>
              @if ($this->selectedComparisonColumnCount() > 0)
                <x-filament::badge color="primary" size="sm">
                  {{ $this->selectedComparisonColumnCount() }}
                </x-filament::badge>
              @endif
            </div>

            {{ $this->form }}
          </div>
        </div>
      </x-slot>

      <x-slot name="activeFilters">
        @foreach ($this->activeFilterIndicators() as $indicator)
          <span
            wire:key="field-matrix-filter-{{ $indicator['key'] }}"
            class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200"
          >
            <span>{{ $indicator['label'] }}: {{ $indicator['value'] }}</span>
            <button
              type="button"
              wire:click="removeFilter('{{ $indicator['key'] }}')"
              class="rounded p-0.5 text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
              aria-label="{{ __('field_matrix.remove_filter', ['label' => $indicator['label']]) }}"
            >
              <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
            </button>
          </span>
        @endforeach

        @if ($this->activeFiltersCount() > 0)
          <button
            type="button"
            wire:click="clearAllFilters"
            class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
          >
            {{ __('field_matrix.clear_all') }}
          </button>
        @endif
      </x-slot>
    </x-filament.data-list-toolbar>

    @php
      $selectedColumns = $this->selectedColumns();
      $columnCount = count($selectedColumns);
    @endphp

    <div @class([
      'max-w-full overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
      'inline-block' => $columnCount === 1,
      'w-full' => $columnCount !== 1,
    ])>
      <table @class([
        'divide-y divide-gray-200 text-sm dark:divide-gray-700',
        'w-max' => $columnCount === 1,
        'min-w-full' => $columnCount !== 1,
      ])>
        <thead class="bg-gray-50 dark:bg-gray-800">
          <tr>
            <th class="min-w-[12rem] max-w-[20rem] px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">
              <button
                type="button"
                wire:click="toggleFieldSortDirection"
                data-testid="field-matrix-sort-trigger"
                class="inline-flex items-center gap-1 font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white"
                aria-sort="{{ $this->fieldSortAriaValue() }}"
                aria-label="{{ $this->fieldSortDirection() === 'asc' ? __('field_matrix.sort_ascending') : __('field_matrix.sort_descending') }}"
              >
                <span>{{ __('field_matrix.field_column') }}</span>
                @if ($this->fieldSortDirection() === 'asc')
                  <x-filament::icon icon="heroicon-m-chevron-up" class="h-4 w-4" data-testid="field-matrix-sort-asc" />
                @else
                  <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" data-testid="field-matrix-sort-desc" />
                @endif
              </button>
            </th>
            @foreach ($selectedColumns as $column)
              <th class="min-w-[10rem] max-w-[16rem] px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">
                {{ $column['channel'] }}<br>
                <span class="text-xs text-gray-500">{{ $column['channel_schema_version'] }}</span>
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          @forelse ($matrix as $row)
            <tr>
              <td class="min-w-[12rem] max-w-[20rem] px-4 py-3">
                <div class="font-medium">{{ $row['uk_label'] }}</div>
                <div class="text-xs text-gray-500">{{ $row['internal_code'] }}</div>
              </td>
              @foreach ($selectedColumns as $column)
                @php
                  $key = $column['channel'].'|'.$column['channel_schema_version'];
                  $cell = $row['cells'][$key] ?? ['label' => 'Not assessed', 'integrity_alarm' => false, 'contexts' => []];
                @endphp
                <td class="min-w-[10rem] max-w-[16rem] px-4 py-3 align-top">
                  <span @class([
                    'inline-flex rounded-md px-2 py-1 text-xs font-medium',
                    'bg-red-100 text-red-800' => $cell['integrity_alarm'],
                    'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => ! $cell['integrity_alarm'] && $cell['label'] === 'Not assessed',
                    'bg-blue-100 text-blue-800' => ! $cell['integrity_alarm'] && $cell['label'] !== 'Not assessed',
                  ])>
                    {{ $cell['label'] }}
                  </span>
                  @if (count($cell['contexts']) > 1)
                    <div class="mt-1 text-xs text-gray-500">{{ count($cell['contexts']) }} контекстів</div>
                  @endif
                </td>
              @endforeach
            </tr>
          @empty
            <tr>
              <td colspan="{{ max($columnCount, 0) + 1 }}" class="px-4 py-6 text-center text-gray-500">
                {{ __('field_matrix.no_registry_data') }}
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</x-filament-panels::page>
