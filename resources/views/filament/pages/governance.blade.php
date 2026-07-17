<x-filament-panels::page>
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 lg:col-span-1">
            <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">DEC / GAP</h3>
            <div class="max-h-[70vh] space-y-1 overflow-y-auto">
                @foreach ($decisions as $decision)
                    <button
                        type="button"
                        wire:click="selectDecision('{{ $decision['id'] }}')"
                        @class([
                            'w-full rounded-lg px-3 py-2 text-left text-sm',
                            'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $selectedId === $decision['id'],
                            'hover:bg-gray-50 dark:hover:bg-gray-800' => $selectedId !== $decision['id'],
                        ])
                    >
                        <div class="font-medium">{{ $decision['id'] }}</div>
                        <div class="text-xs text-gray-500">{{ $decision['title'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="space-y-4 lg:col-span-2">
            @if ($selectedDecision)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ $selectedDecision['id'] }} — {{ $selectedDecision['title'] }}
                    </h3>
                    <div class="prose prose-sm mt-4 max-w-none dark:prose-invert whitespace-pre-wrap">{{ $selectedDecision['body'] }}</div>
                </div>
            @endif

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Джерела доказів</h3>
                @forelse ($selectedSources as $source)
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
    </div>
</x-filament-panels::page>
