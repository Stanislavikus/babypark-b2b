<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament.data-list-toolbar
      :has-filters="true"
      :filters-count="1"
      panel-id="governance-toolbar-panel"
      :filters-label="__('governance.filters')"
    >
      <x-slot name="search">
        <label for="governance-search" class="sr-only">{{ __('governance.search_label') }}</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="governance-search"
            type="search"
            :placeholder="__('governance.search_placeholder')"
            :aria-label="__('governance.search_label')"
            wire:model.live.debounce.300ms="search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="panel">
        <div
          class="space-y-4 p-4"
          data-testid="governance-toolbar-panel"
        >
          <fieldset data-testid="governance-document-type-filter">
            <legend class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
              {{ __('governance.document_type') }}
            </legend>

            <div class="space-y-3">
              <label
                for="governance-document-type-dec"
                data-testid="governance-document-type-dec"
                @class([
                  'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2',
                  'border-primary-300 bg-primary-50 ring-1 ring-primary-200 dark:border-primary-500/40 dark:bg-primary-500/10 dark:ring-primary-500/30' => $documentType === 'DEC',
                  'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' => $documentType !== 'DEC',
                ])
              >
                <input
                  id="governance-document-type-dec"
                  type="radio"
                  name="governance-document-type"
                  value="DEC"
                  class="mt-1"
                  wire:click="setDocumentType('DEC')"
                  @checked($documentType === 'DEC')
                />
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium text-gray-900 dark:text-white">
                    {{ __('governance.dec') }}
                    <x-filament::badge color="gray" size="sm" class="ms-1">{{ $this->decCount() }}</x-filament::badge>
                  </span>
                  <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                    {{ __('governance.dec_description') }}
                  </span>
                </span>
              </label>

              <label
                for="governance-document-type-gap"
                data-testid="governance-document-type-gap"
                @class([
                  'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2',
                  'border-primary-300 bg-primary-50 ring-1 ring-primary-200 dark:border-primary-500/40 dark:bg-primary-500/10 dark:ring-primary-500/30' => $documentType === 'GAP',
                  'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' => $documentType !== 'GAP',
                ])
              >
                <input
                  id="governance-document-type-gap"
                  type="radio"
                  name="governance-document-type"
                  value="GAP"
                  class="mt-1"
                  wire:click="setDocumentType('GAP')"
                  @checked($documentType === 'GAP')
                />
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium text-gray-900 dark:text-white">
                    {{ __('governance.gap') }}
                    <x-filament::badge color="gray" size="sm" class="ms-1">{{ $this->gapCount() }}</x-filament::badge>
                  </span>
                  <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                    {{ __('governance.gap_description') }}
                  </span>
                </span>
              </label>
            </div>
          </fieldset>
        </div>
      </x-slot>

      <x-slot name="activeFilters">
        <button
          type="button"
          data-testid="governance-document-type-indicator"
          x-on:click="$dispatch('open-modal', { id: 'governance-toolbar-panel' })"
          class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200"
        >
          <span>{{ $this->documentTypeIndicatorLabel() }}</span>
        </button>
      </x-slot>
    </x-filament.data-list-toolbar>

    <div class="space-y-2">
      @forelse ($this->filteredDecisions() as $decision)
        @php
          $isExpanded = $expandedCardId === $decision['id'];
        @endphp
        <div
          wire:key="governance-{{ $decision['id'] }}"
          class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
          <button
            type="button"
            wire:click="toggleCard('{{ $decision['id'] }}')"
            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
          >
            <div class="min-w-0">
              <div class="font-medium text-gray-900 dark:text-white">{{ $decision['id'] }}</div>
              <div class="truncate text-sm text-gray-500">{{ $decision['title'] }}</div>
            </div>
            <x-filament::icon
              icon="heroicon-m-chevron-down"
              @class([
                'h-5 w-5 shrink-0 text-gray-400 transition-transform',
                'rotate-180' => $isExpanded,
              ])
            />
          </button>

          @if ($isExpanded && $expandedDecision)
            <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ $expandedDecision['id'] }} — {{ $expandedDecision['title'] }}
              </h3>
              <div class="prose prose-sm mt-4 max-w-none whitespace-pre-wrap dark:prose-invert">{{ $expandedDecision['body'] }}</div>

              <div class="mt-6">
                <h4 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('governance.evidence_sources') }}</h4>
                @forelse ($expandedSources as $source)
                  <div class="mb-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                    <div class="font-medium">{{ $source['source_title'] }}</div>
                    <div class="text-xs text-gray-500">{{ $source['source_organization'] }} · {{ $source['verified_at'] }}</div>
                    @if ($source['source_url_or_state'] !== 'not_applicable')
                      <a href="{{ $source['source_url_or_state'] }}" target="_blank" class="mt-1 block text-primary-600 hover:underline">
                        {{ $source['source_url_or_state'] }}
                      </a>
                    @endif
                    <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $source['evidence_note'] }}</div>
                  </div>
                @empty
                  <p class="text-sm text-gray-500">{{ __('governance.no_evidence_sources') }}</p>
                @endforelse
              </div>

              @php
                $copyParts = array_values(array_filter([
                  $expandedDecision['id'].' — '.$expandedDecision['title'],
                  '',
                  $expandedDecision['body'],
                  '',
                  __('governance.evidence_sources').':',
                  ...array_map(function (array $source): string {
                    $lines = array_values(array_filter([
                      '- '.$source['source_title'],
                      '  '.$source['source_organization'].' · '.$source['verified_at'],
                      $source['source_url_or_state'] !== 'not_applicable' ? '  '.$source['source_url_or_state'] : null,
                      '  '.$source['evidence_note'],
                    ], fn (?string $value): bool => $value !== null && $value !== ''));
                    return implode("\n", $lines);
                  }, $expandedSources),
                ], fn (?string $value): bool => $value !== null && $value !== ''));
                $copyText = implode("\n", $copyParts);
              @endphp

              <div class="mt-6 flex justify-end">
                <x-filament.clipboard-copy-button
                  :text="$copyText"
                  :label="__('governance.copy_expanded')"
                  :copied-label="__('governance.copied')"
                  icon="heroicon-o-clipboard-document"
                />
              </div>
            </div>
          @endif
        </div>
      @empty
        <p class="text-sm text-gray-500">{{ __('governance.no_results') }}</p>
      @endforelse
    </div>
  </div>
</x-filament-panels::page>
