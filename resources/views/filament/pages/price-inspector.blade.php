<x-filament-panels::page>
    <form wire:submit="resolvePrice">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit">
                {{ __('price_inspector.form.check_price') }}
            </x-filament::button>
        </div>
    </form>

    @if ($presentation !== null)
        @php
            $tone = $presentation['tone'];
            $toneClasses = match ($tone) {
                'success' => 'border-success-300 bg-success-50 text-success-800 dark:border-success-500 dark:bg-success-500/10 dark:text-success-400',
                'warning' => 'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500 dark:bg-warning-500/10 dark:text-warning-400',
                'critical' => 'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-500 dark:bg-danger-500/10 dark:text-danger-400',
                default => 'border-gray-300 bg-gray-50 text-gray-800 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200',
            };
            $toneIcon = match ($tone) {
                'success' => '✓',
                'warning' => '⚠',
                'critical' => '⛔',
                default => '•',
            };
            $showActions = in_array($tone, ['warning', 'critical'], true);
        @endphp

        <div class="mt-6 space-y-6">
            {{-- Summary block --}}
            <div class="rounded-xl border p-6 {{ $toneClasses }}">
                <div class="flex items-start gap-3">
                    <span class="text-2xl leading-none" aria-hidden="true">{{ $toneIcon }}</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-xl font-bold">{{ $presentation['headline'] }}</h2>
                        @if ($presentation['price_summary'] !== null)
                            <p class="mt-2 text-3xl font-semibold">{{ $presentation['price_summary'] }}</p>
                        @endif
                        <p class="mt-2 text-base opacity-90">{{ $presentation['summary'] }}</p>
                    </div>
                </div>
            </div>

            {{-- Recommended actions --}}
            @if ($showActions && count($presentation['recommended_actions']) > 0)
                <x-filament::section :heading="__('price_inspector.section.what_to_fix')">
                    <ol class="list-decimal space-y-2 pl-5">
                        @foreach ($presentation['recommended_actions'] as $action)
                            <li wire:key="action-{{ $loop->index }}">
                                <x-price-inspector-nav-link
                                    :href="$action['url']"
                                    :label="$action['label']"
                                />
                            </li>
                        @endforeach
                    </ol>
                </x-filament::section>
            @endif

            {{-- Decision path --}}
            <x-filament::section :heading="__('price_inspector.section.decision_path')">
                <div class="space-y-4">
                    @foreach ($presentation['source_steps'] as $index => $step)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                             wire:key="step-{{ $index }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <div>
                                    <span class="font-semibold">{{ $step['source_label'] }}</span>
                                    @if ($step['source_name'] !== null)
                                        <span class="text-gray-500 dark:text-gray-400">— {{ $step['source_name'] }}</span>
                                    @endif
                                </div>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ $step['outcome_label'] }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm">{{ $step['explanation'] }}</p>
                            @if ($step['action'] !== null)
                                <div class="mt-2">
                                    <x-price-inspector-nav-link
                                        :href="$step['action']['url']"
                                        :label="$step['action']['label'].' →'"
                                        class="text-sm font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                    />
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Technical details (collapsed) --}}
            <details class="rounded-lg border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer select-none px-4 py-3 font-medium text-gray-700 dark:text-gray-300">
                    {{ __('price_inspector.section.technical_details') }}
                </summary>
                <div class="space-y-4 border-t border-gray-200 px-4 py-4 dark:border-gray-700">
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.status') }}</span>
                        <p class="font-mono text-sm">{{ $presentation['technical_details']['status'] }}</p>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.reason_codes') }}</span>
                        <p class="font-mono text-sm">{{ implode(', ', $presentation['technical_details']['reason_codes']) }}</p>
                    </div>

                    @if ($presentation['technical_details']['failure'] !== null)
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.failure') }}</span>
                            <p class="font-mono text-sm">{{ $presentation['technical_details']['failure']['reason'] }}</p>
                            <p class="mt-1 text-sm">{{ $presentation['technical_details']['failure']['message'] }}</p>
                            @if ($presentation['technical_details']['failure']['context'] !== [])
                                <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ json_encode($presentation['technical_details']['failure']['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            @endif
                        </div>
                    @endif

                    @if ($presentation['technical_details']['price'] !== null)
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.price') }}</span>
                            <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ json_encode($presentation['technical_details']['price'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif

                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.context') }}</span>
                        <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ json_encode($presentation['technical_details']['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('price_inspector.technical.trace') }}</span>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left">
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_index') }}</th>
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_source') }}</th>
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_status') }}</th>
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_reason') }}</th>
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_price_list_id') }}</th>
                                        <th class="py-2 pr-4">{{ __('price_inspector.technical.trace_amount') }}</th>
                                        <th class="py-2">{{ __('price_inspector.technical.trace_metadata') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($presentation['technical_details']['trace'] as $traceIndex => $traceStep)
                                        <tr class="border-b border-gray-100 dark:border-gray-800"
                                            wire:key="trace-{{ $traceIndex }}">
                                            <td class="py-2 pr-4">{{ $traceIndex + 1 }}</td>
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $traceStep['source'] }}</td>
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $traceStep['status'] }}</td>
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $traceStep['reason'] }}</td>
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $traceStep['price_list_id'] ?? '—' }}</td>
                                            <td class="py-2 pr-4">
                                                @if ($traceStep['amount'] !== null)
                                                    {{ number_format($traceStep['amount'], 2, ',', ' ') }} {{ $traceStep['currency'] }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="py-2">
                                                @if ($traceStep['metadata'] !== [])
                                                    <code class="text-xs">{{ json_encode($traceStep['metadata'], JSON_UNESCAPED_UNICODE) }}</code>
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
                        <div x-data="{ copied: false }">
                            <x-filament::button
                                type="button"
                                x-on:click="navigator.clipboard.writeText(@js($presentedOutput)); copied = true; setTimeout(() => copied = false, 2000)"
                                color="gray"
                                size="sm"
                            >
                                <span x-show="!copied">{{ __('price_inspector.section.copy_diagnostics') }}</span>
                                <span x-show="copied" x-cloak>{{ __('price_inspector.section.copied') }}</span>
                            </x-filament::button>
                            <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-4 text-xs dark:bg-gray-900"
                                 id="price-inspector-diagnostics">{{ $presentedOutput }}</pre>
                        </div>
                    @endif
                </div>
            </details>
        </div>
    @endif
</x-filament-panels::page>
