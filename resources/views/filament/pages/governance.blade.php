<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700">
            <button
                type="button"
                wire:click="setActiveTab('DEC')"
                @class([
                    'border-b-2 px-4 py-2 text-sm font-medium transition',
                    'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' => $activeTab === 'DEC',
                    'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activeTab !== 'DEC',
                ])
            >
                DEC ({{ $this->decCount() }})
            </button>
            <button
                type="button"
                wire:click="setActiveTab('GAP')"
                @class([
                    'border-b-2 px-4 py-2 text-sm font-medium transition',
                    'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' => $activeTab === 'GAP',
                    'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activeTab !== 'GAP',
                ])
            >
                GAP ({{ $this->gapCount() }})
            </button>
        </div>

        <div>
            <label for="governance-search" class="sr-only">Пошук</label>
            <input
                id="governance-search"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Пошук за номером або заголовком..."
                class="block w-full max-w-md rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
            />
        </div>

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
