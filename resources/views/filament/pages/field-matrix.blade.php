<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->form }}

        @php
            $selectedColumns = $this->selectedColumns();
            $columnCount = count($selectedColumns);
        @endphp

        <div @class([
            'max-w-full overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
            'inline-block' => $columnCount === 1,
            'w-full' => $columnCount !== 1,
        ])>
            <table @class([
                'divide-y divide-gray-200 text-sm dark:divide-gray-700',
                'w-max' => $columnCount === 1,
                'min-w-full' => $columnCount !== 1,
            ])>
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="min-w-[12rem] max-w-[20rem] px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">
                            Поле
                        </th>
                        @foreach ($selectedColumns as $column)
                            <th class="min-w-[10rem] max-w-[16rem] px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">
                                {{ $column['channel'] }}<br>
                                <span class="text-xs text-gray-500">{{ $column['channel_schema_version'] }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($matrix as $row)
                        <tr>
                            <td class="min-w-[12rem] max-w-[20rem] px-4 py-3">
                                <div class="font-medium">{{ $row['uk_label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $row['internal_code'] }}</div>
                            </td>
                            @foreach ($selectedColumns as $column)
                                @php
                                    $key = $column['channel'].'|'.$column['channel_schema_version'];
                                    $cell = $row['cells'][$key] ?? ['label' => 'Not assessed', 'integrity_alarm' => false, 'contexts' => []];
                                @endphp
                                <td class="min-w-[10rem] max-w-[16rem] px-4 py-3 align-top">
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
                            <td colspan="{{ max($columnCount, 0) + 1 }}" class="px-4 py-6 text-center text-gray-500">
                                Немає даних реєстру.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
