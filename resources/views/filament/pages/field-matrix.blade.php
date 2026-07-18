<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament.data-list-toolbar
      :filters-count="$this->activeFiltersCount()"
      :has-filters="true"
    >
      <x-slot name="search">
        <label for="field-matrix-search" class="sr-only">Пошук полів</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="field-matrix-search"
            type="search"
            placeholder="Назва, код..."
            aria-label="Пошук полів"
            wire:model.live.debounce.300ms="data.search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="filters">
        <div class="space-y-4">
          <div>
            <label for="field-matrix-field-group" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
              Група
            </label>
            <x-filament::input.wrapper>
              <x-filament::input.select
                id="field-matrix-field-group"
                wire:model.live="data.fieldGroup"
              >
                <option value="">Усі групи</option>
                @foreach ($this->fieldGroupOptions() as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </x-filament::input.select>
            </x-filament::input.wrapper>
          </div>

          <div>
            <label for="field-matrix-binding-strategy" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
              Product / Variant / Both
            </label>
            <x-filament::input.wrapper>
              <x-filament::input.select
                id="field-matrix-binding-strategy"
                wire:model.live="data.bindingStrategy"
              >
                <option value="">Усі рівні</option>
                @foreach ($this->bindingStrategyOptions() as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </x-filament::input.select>
            </x-filament::input.wrapper>
          </div>

          <div>
            <label for="field-matrix-scope" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
              Походження поля
            </label>
            <x-filament::input.wrapper>
              <x-filament::input.select
                id="field-matrix-scope"
                wire:model.live="data.scope"
              >
                <option value="">Усі джерела</option>
                @foreach ($this->scopeOptions() as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </x-filament::input.select>
            </x-filament::input.wrapper>
          </div>
        </div>
      </x-slot>

      <x-slot name="actions">
        <x-filament::dropdown placement="bottom-end" shift width="md">
          <x-slot name="trigger">
            <x-filament::button
              color="gray"
              icon="heroicon-m-view-columns"
              data-testid="compare-channels-trigger"
            >
              <span class="flex items-center gap-2">
                <span>Порівняти канали</span>
                @if ($this->selectedComparisonColumnCount() > 0)
                  <x-filament::badge color="primary" size="sm">
                    {{ $this->selectedComparisonColumnCount() }}
                  </x-filament::badge>
                @endif
              </span>
            </x-filament::button>
          </x-slot>

          <div class="p-4">
            {{ $this->form }}
          </div>
        </x-filament::dropdown>
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
              aria-label="Видалити фільтр {{ $indicator['label'] }}"
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
            Очистити все
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
              Поле
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
                Немає даних реєстру.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</x-filament-panels::page>
