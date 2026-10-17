<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->cards as $card)
            <article
                wire:key="integration-card-{{ $card['platform_code'] }}"
                class="flex flex-col justify-between gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900"
            >
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $card['platform_name'] }}
                        </h2>
                        <x-filament::badge :color="$card['status_color']">
                            {{ $card['status_label'] }}
                        </x-filament::badge>
                    </div>

                    @if (filled($card['runtime_overlay_label']) && (int) $card['account_count'] === 1)
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $card['runtime_overlay_label'] }}
                        </p>
                    @elseif (filled($card['secondary_line']))
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $card['secondary_line'] }}
                        </p>
                    @endif
                </div>

                <div>
                    @if ($card['primary_action'] !== 'none' && filled($card['primary_action_url']))
                        <x-filament::button
                            tag="a"
                            :href="$card['primary_action_url']"
                            color="primary"
                        >
                            {{ $card['primary_action_label'] }}
                        </x-filament::button>
                    @elseif (filled($card['secondary_action_hint']))
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $card['secondary_action_hint'] }}
                        </p>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('connectors.ui.integrations.empty') }}
            </p>
        @endforelse
    </div>
</x-filament-panels::page>
