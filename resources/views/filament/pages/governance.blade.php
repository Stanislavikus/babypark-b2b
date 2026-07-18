<x-filament-panels::page>
  <div class="space-y-4">
    <x-filament.data-list-toolbar
      :has-filters="false"
      panel-id="governance-toolbar-panel"
      :mobile-context-indicator="$activeTab"
    >
      <x-slot name="search">
        <label for="governance-search" class="sr-only">Пошук</label>
        <x-filament::input.wrapper>
          <x-filament::input
            id="governance-search"
            type="search"
            placeholder="Пошук за номером або заголовком..."
            aria-label="Пошук за номером або заголовком"
            wire:model.live.debounce.300ms="search"
          />
        </x-filament::input.wrapper>
      </x-slot>

      <x-slot name="actions">
        <x-filament::tabs data-testid="governance-desktop-tabs">
          <x-filament::tabs.item
            :active="$activeTab === 'DEC'"
            :badge="(string) $this->decCount()"
            wire:click="setActiveTab('DEC')"
          >
            DEC
          </x-filament::tabs.item>

          <x-filament::tabs.item
            :active="$activeTab === 'GAP'"
            :badge="(string) $this->gapCount()"
            wire:click="setActiveTab('GAP')"
          >
            GAP
          </x-filament::tabs.item>
        </x-filament::tabs>
      </x-slot>

      <x-slot name="panel">
        <div
          class="space-y-4 p-4"
          data-testid="governance-toolbar-panel"
        >
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Тип документа</h3>

          <div class="space-y-2" data-testid="governance-mobile-document-type">
            <button
              type="button"
              wire:click="setActiveTab('DEC')"
              @class([
                'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-medium',
                'bg-primary-50 text-primary-700 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-400 dark:ring-primary-500/30' => $activeTab === 'DEC',
                'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' => $activeTab !== 'DEC',
              ])
            >
              <span>DEC</span>
              <x-filament::badge color="gray" size="sm">{{ $this->decCount() }}</x-filament::badge>
            </button>

            <button
              type="button"
              wire:click="setActiveTab('GAP')"
              @class([
                'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-medium',
                'bg-primary-50 text-primary-700 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-400 dark:ring-primary-500/30' => $activeTab === 'GAP',
                'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' => $activeTab !== 'GAP',
              ])
            >
              <span>GAP</span>
              <x-filament::badge color="gray" size="sm">{{ $this->gapCount() }}</x-filament::badge>
            </button>
          </div>
        </div>
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
                <h4 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Джерела доказів</h4>
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
                  <p class="text-sm text-gray-500">Немає джерел для цього рішення.</p>
                @endforelse
              </div>
            </div>
          @endif
        </div>
      @empty
        <p class="text-sm text-gray-500">Немає записів для відображення.</p>
      @endforelse
    </div>
  </div>
</x-filament-panels::page>
