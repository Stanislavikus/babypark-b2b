<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('connectors.ui.columns.source') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $sourceLabel }}
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('connectors.ui.columns.captured_at') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $capturedAt ?? __('connectors.ui.common.dash') }}
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('connectors.ui.columns.field_count') }}
                </dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $fieldCount }}
                </dd>
            </div>
            @if ($snapshotStateLabel !== null)
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
