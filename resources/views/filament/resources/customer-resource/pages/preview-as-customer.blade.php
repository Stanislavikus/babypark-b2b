<x-filament-panels::page>
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-600 dark:bg-amber-950 dark:text-amber-100">
        <strong>Режим лише для читання.</strong>
        Це попередній перегляд каталогу від імені клієнта «{{ $customer->name }}».
        Сесія клієнта не створюється; додавання до кошика та оформлення замовлень недоступні.
        <span class="block mt-1 text-xs opacity-80">effectiveAt: {{ $effectiveAt->utc()->format('Y-m-d H:i:s.u') }} UTC</span>
    </div>

    <div class="mb-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="text-sm font-medium">Пошук</label>
            <input type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900" placeholder="Назва, артикул, бренд…">
        </div>
        <div>
            <label class="text-sm font-medium">Кількість</label>
            <input type="number" min="1" wire:model.live="quantity" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900">
        </div>
        <div>
            <label class="text-sm font-medium">Сортування</label>
            <select wire:model.live="sortBy" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                <option value="sku">Артикул</option>
                <option value="name">Назва</option>
                <option value="category">Категорія</option>
                <option value="brand">Бренд</option>
                <option value="stock">Наявність</option>
                <option value="price">Ціна</option>
                <option value="rrp">РРЦ</option>
                <option value="margin">Маржа</option>
            </select>
        </div>
        <div>
            <label class="text-sm font-medium">Напрямок</label>
            <select wire:model.live="sortDir" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                <option value="asc">За зростанням</option>
                <option value="desc">За спаданням</option>
            </select>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <div class="min-w-48">
            <label class="text-sm font-medium">Категорії</label>
            <select wire:model.live="selectedCategories" multiple class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900" size="4">
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-48">
            <label class="text-sm font-medium">Бренди</label>
            <select wire:model.live="selectedBrands" multiple class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900" size="4">
                @foreach($brands as $brand)
                    <option value="{{ $brand }}">{{ $brand }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <x-filament::button wire:click="resetFilters" color="gray" size="sm">Скинути фільтри</x-filament::button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">Товар</th>
                    <th class="px-3 py-2 text-left font-medium">Варіант</th>
                    <th class="px-3 py-2 text-left font-medium">Стан</th>
                    <th class="px-3 py-2 text-right font-medium">Ціна</th>
                    <th class="px-3 py-2 text-left font-medium">Валюта</th>
                    <th class="px-3 py-2 text-left font-medium">Джерело</th>
                    <th class="px-3 py-2 text-left font-medium">Замовлення</th>
                    <th class="px-3 py-2 text-left font-medium">Причина</th>
                    <th class="px-3 py-2 text-left font-medium">Інспектор</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-3 py-2">{{ $row['product']->name }} <span class="text-gray-400">#{{ $row['product_id'] }}</span></td>
                        <td class="px-3 py-2">{{ $row['displayed_variant_id'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $row['display_state'] === 'configuration_error' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $row['display_state_label'] }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right">{{ $row['price_label'] ?? ($row['price'] !== null ? number_format($row['price'], 2, '.', ' ') : '—') }}</td>
                        <td class="px-3 py-2">{{ $row['currency'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row['price_source'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row['orderable'] ? 'Так' : 'Ні' }}</td>
                        <td class="px-3 py-2">{{ $row['primary_reason'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if($row['inspect_url'])
                                <a href="{{ $row['inspect_url'] }}" class="text-primary-600 hover:underline" target="_blank">Inspect</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-6 text-center text-gray-500">Немає товарів</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $products->links() }}
    </div>
</x-filament-panels::page>
