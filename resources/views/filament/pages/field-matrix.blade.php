<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">
                Колонки (до 4, channel + channel_schema_version)
            </label>
            <select
                wire:model.live="selectedColumnKeys"
                multiple
                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                size="6"
            >
                @foreach ($this->columnOptions() as $index => $label)
                    <option value="{{ $index }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Поле</th>
                        @foreach ($this->selectedColumns() as $column)
                            <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">
                                {{ $column['channel'] }}<br>
                                <span class="text-xs text-gray-500">{{ $column['channel_schema_version'] }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($matrix as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $row['uk_label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $row['internal_code'] }}</div>
                            </td>
                            @foreach ($this->selectedColumns() as $column)
                                @php
                                    $key = $column['channel'].'|'.$column['channel_schema_version'];
                                    $cell = $row['cells'][$key] ?? ['label' => 'Not assessed', 'integrity_alarm' => false, 'contexts' => []];
                                @endphp
                                <td class="px-4 py-3 align-top">
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-1 text-xs font-medium',
                                        'bg-red-100 text-red-800' => $cell['integrity_alarm'],
                                        'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => ! $cell['integrity_alarm'] && $cell['label'] === 'Not assessed',
                                        'bg-blue-100 text-blue-800' => ! $cell['integrity_alarm'] && $cell['label'] !== 'Not assessed',
                                    ])>
                                        {{ $cell['label'] }}
                                    </span>
                                    @if (count($cell['contexts']) > 1)
                                        <div class="mt-1 text-xs text-gray-500">{{ count($cell['contexts']) }} контекстів</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($this->selectedColumns()) + 1 }}" class="px-4 py-6 text-center text-gray-500">
                                Немає даних реєстру.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
