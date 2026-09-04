@php
    /** @var \App\Models\ConnectorAccount $record */
    $record = $record ?? null;

    $lastSuccessfulCheckAt = $record?->last_successful_check_at;
    $catalogTotalCount = null;
    $catalogCountKnown = false;
    $imagesAccessConfirmed = false;

    if ($record !== null) {
        $latestSuccessfulCheck = $record->connectionChecks()
            ->where('status', \App\Enums\ConnectorConnectionCheckStatus::Succeeded)
            ->latest('finished_at')
            ->first();

        if ($latestSuccessfulCheck !== null) {
            $params = $latestSuccessfulCheck->safe_message_parameters ?? null;
            if (is_array($params) && array_key_exists('catalog_total_count', $params) && is_int($params['catalog_total_count'])) {
                $catalogTotalCount = $params['catalog_total_count'];
                $catalogCountKnown = true;
            }
            if (is_array($params) && array_key_exists('images_access_confirmed', $params) && $params['images_access_confirmed'] === true) {
                $imagesAccessConfirmed = true;
            }
        }
    }

    $fieldsAccessConfirmed = $record?->last_successful_discovery_at !== null;

    $syncConfigurationId = null;
    if ($record !== null) {
        $syncConfigurationId = $record->syncConfigurations()
            ->orderByDesc('created_at')
            ->value('id');
    }
@endphp

<div class="space-y-4">
    <div class="space-y-2">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Перевірка не змінює дані в Magento.
        </p>
    </div>

    <div class="space-y-3 rounded-xl border border-gray-200 bg-white/70 p-4 dark:border-white/10 dark:bg-black/10">
        <p class="text-sm font-medium text-gray-950 dark:text-white">
            ЩО МИ ПЕРЕВІРИЛИ
        </p>

        <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-950 dark:text-white">Каталог</span>
                    <span class="text-gray-600 dark:text-gray-400">—</span>
                    <span>{{ $lastSuccessfulCheckAt !== null ? 'доступ підтверджено' : 'потребує уваги' }}</span>
                </div>
                @if ($catalogCountKnown)
                    <span class="text-gray-600 dark:text-gray-400">
                        {{ $catalogTotalCount === 0 ? 'Каталог поки порожній.' : ('Знайдено '.$catalogTotalCount.' товарів') }}
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-950 dark:text-white">Поля</span>
                    <span class="text-gray-600 dark:text-gray-400">—</span>
                    <span>{{ $fieldsAccessConfirmed ? 'доступ підтверджено' : 'потребує уваги' }}</span>
                </div>
                @if ($syncConfigurationId !== null)
                    <a
                        class="text-primary-600 hover:underline dark:text-primary-400"
                        href="{{ \App\Filament\Pages\Sync\ManageSyncFieldMappings::getUrl(['account' => (string) $record->id, 'configuration' => (string) $syncConfigurationId]) }}"
                    >
                        Переглянути поля
                    </a>
                @endif
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-950 dark:text-white">Зображення</span>
                    <span class="text-gray-600 dark:text-gray-400">—</span>
                    <span>{{ $imagesAccessConfirmed ? 'доступ підтверджено' : 'не перевірено' }}</span>
                </div>
            </div>
        </div>

        @if ($lastSuccessfulCheckAt !== null)
            <p class="text-xs text-gray-600 dark:text-gray-400">
                Остання успішна перевірка: {{ \App\Support\Connectors\ConnectorUiFormatter::formatDateTime($lastSuccessfulCheckAt) }}
            </p>
        @endif
    </div>
</div>
