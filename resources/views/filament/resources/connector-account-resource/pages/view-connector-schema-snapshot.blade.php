<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if (! $layerBPresentation && $sourceLabel !== null)
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ __('connectors.ui.columns.source') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $sourceLabel }}
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $layerBPresentation
                        ? __('sync_mappings.available_fields_last_checked')
                        : __('connectors.ui.columns.captured_at') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $capturedAt ?? __('connectors.ui.common.dash') }}
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('sync_mappings.available_fields_count') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $fieldCount }}
                </dd>
            </div>
            @if ($classificationSummary !== [])
                <div class="sm:col-span-2">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        @foreach ($classificationSummary as $item)
                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['label'] }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $item['count'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            @if (! $layerBPresentation && $snapshotStateLabel !== null)
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ __('connectors.ui.columns.snapshot_state') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $snapshotStateLabel }}
                    </dd>
                </div>
            @endif
        </dl>
    </x-filament::section>

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
