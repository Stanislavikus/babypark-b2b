<x-filament-panels::page>
    <form wire:submit="resolvePrice">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit">
                Перевірити ціну
            </x-filament::button>
        </div>
    </form>

    @if ($resultSummary !== null)
        <x-filament::section class="mt-6" heading="Результат">
            <div class="space-y-4">
                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Статус</span>
                    <p class="text-lg font-semibold">{{ $resultSummary['status'] }}</p>
                </div>

                @if ($resultSummary['price'] !== null)
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Нетто (ефективна)</span>
                            <p>{{ number_format($resultSummary['price']['effective_net'], 2, ',', ' ') }} {{ $resultSummary['price']['currency'] }}</p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Брутто</span>
                            <p>{{ number_format($resultSummary['price']['gross'], 2, ',', ' ') }} {{ $resultSummary['price']['currency'] }}</p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Джерело</span>
                            <p>{{ $resultSummary['price']['source'] }}</p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">ПДВ</span>
                            <p>{{ number_format($resultSummary['price']['vat_rate'], 2, ',', ' ') }}%</p>
                        </div>
                    </div>
                @endif

                @if ($resultSummary['failure'] !== null)
                    <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 dark:border-danger-500 dark:bg-danger-500/10">
                        <p class="font-medium text-danger-700 dark:text-danger-400">
                            {{ $resultSummary['failure']['reason'] }}
                        </p>
                        <p class="mt-1 text-sm">{{ $resultSummary['failure']['message'] }}</p>
                        @if ($resultSummary['failure']['context'] !== [])
                            <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($resultSummary['failure']['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    </div>
                @endif

                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Коди причин</span>
                    <p class="text-sm">{{ implode(', ', $resultSummary['reason_codes']) }}</p>
                </div>

                <div>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Trace</span>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Джерело</th>
                                    <th class="py-2 pr-4">Статус</th>
                                    <th class="py-2 pr-4">Причина</th>
                                    <th class="py-2 pr-4">Сума</th>
                                    <th class="py-2">Метадані</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($presenter = app(\App\Services\Pricing\Resolution\PriceResolutionTracePresenter::class))
                                @foreach ($resultSummary['trace'] as $index => $step)
                                    <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="step-{{ $index }}">
                                        <td class="py-2 pr-4">{{ $index + 1 }}</td>
                                        <td class="py-2 pr-4">{{ $presenter->sourceLabel(\App\Services\Pricing\Resolution\PriceResolutionSource::from($step['source'])) }}</td>
                                        <td class="py-2 pr-4">{{ $presenter->stepStatusLabel(\App\Services\Pricing\Resolution\PriceResolutionStepStatus::from($step['status'])) }}</td>
                                        <td class="py-2 pr-4">{{ $presenter->reasonLabel(\App\Services\Pricing\Resolution\PriceResolutionReason::from($step['reason'])) }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($step['amount'] !== null)
                                                {{ number_format($step['amount'], 2, ',', ' ') }} {{ $step['currency'] }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2">
                                            @if ($step['metadata'] !== [])
                                                <code class="text-xs">{{ json_encode($step['metadata'], JSON_UNESCAPED_UNICODE) }}</code>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($presentedOutput !== null)
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Текстовий вивід</span>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs dark:bg-gray-900">{{ $presentedOutput }}</pre>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
