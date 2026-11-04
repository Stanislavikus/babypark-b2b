<x-filament-panels::page>
    @if (filled($connectAnotherHint))
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ $connectAnotherHint }}
        </p>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($rows as $row)
                <li
                    wire:key="platform-connection-{{ $row['id'] }}"
                    @class([
                        'flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between',
                    ])
                    @if (filled($row['runtime_overlay_label']))
                        wire:poll.5s="refreshConnectionState"
                    @endif
                >
                    <div class="space-y-2">
                        <div class="font-medium text-gray-950 dark:text-white">
                            {{ $row['name'] }}
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$row['status_color']">
                                {{ $row['status_label'] }}
                            </x-filament::badge>
                            @if (filled($row['runtime_overlay_label']))
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $row['runtime_overlay_label'] }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <x-filament::button
                        tag="a"
                        :href="$row['url']"
                        color="gray"
                    >
                        {{ __('connectors.ui.integrations.actions.open') }}
                    </x-filament::button>
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
