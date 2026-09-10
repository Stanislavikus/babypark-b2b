<div class="space-y-8">

    {{-- Flash message --}}
    @if($flashMessage)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 2500)"
            x-show="show"
            x-transition:leave="transition ease-in duration-300"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg"
        >
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Page title --}}
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Кабінет</h1>
        <p class="mt-1 text-sm text-gray-500">Ласкаво просимо, {{ $customer->short_name ?? $customer->name }}</p>
    </div>

    {{-- C.1 Contact cards --}}
    <div class="grid gap-4 sm:grid-cols-2">

        {{-- Your details --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Ваші дані</h2>
            <dl class="space-y-2">
                <div class="flex justify-between text-sm">
                    <dt class="text-gray-500">Назва</dt>
                    <dd class="font-medium text-gray-900 text-right">{{ $customer->name }}</dd>
                </div>
                @if($customer->email)
                    <div class="flex justify-between text-sm">
                        <dt class="text-gray-500">Email</dt>
                        <dd class="font-medium text-gray-900">
                            <a href="mailto:{{ $customer->email }}" class="hover:text-primary-600">{{ $customer->email }}</a>
                        </dd>
                    </div>
                @endif
                @if($customer->edrpou)
                    <div class="flex justify-between text-sm">
                        <dt class="text-gray-500">ЄДРПОУ</dt>
                        <dd class="font-mono font-medium text-gray-900">{{ $customer->edrpou }}</dd>
                    </div>
                @endif
                @if($customer->ipn)
                    <div class="flex justify-between text-sm">
                        <dt class="text-gray-500">ІПН</dt>
                        <dd class="font-mono font-medium text-gray-900">{{ $customer->ipn }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Manager contact --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Ваш менеджер</h2>
            @if($manager['name'] || $manager['phone'])
                <dl class="space-y-2">
                    @if($manager['name'])
                        <div class="flex justify-between text-sm">
                            <dt class="text-gray-500">Ім'я</dt>
                            <dd class="font-medium text-gray-900">{{ $manager['name'] }}</dd>
                        </div>
                    @endif
                    @if($manager['phone'])
                        <div class="flex justify-between text-sm">
                            <dt class="text-gray-500">Телефон</dt>
                            <dd class="font-medium text-gray-900">
                                <a href="tel:{{ $manager['phone'] }}" class="hover:text-primary-600">{{ $manager['phone'] }}</a>
                            </dd>
                        </div>
                    @endif
                </dl>
            @else
                <p class="text-sm text-gray-400">Менеджера не призначено</p>
            @endif
        </div>
    </div>

    {{-- C.2 Credit block --}}
    <div>
        <h2 class="text-base font-semibold text-gray-900 mb-3">Кредитний стан</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Кредитний ліміт</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">
                    {{ number_format((float) $customer->credit_limit, 2, ',', ' ') }} ₴
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Поточний борг</p>
                <p class="mt-1 text-2xl font-bold {{ (float)$customer->current_debt > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ number_format((float) $customer->current_debt, 2, ',', ' ') }} ₴
                </p>
            </div>

            @php
                $available = max(0, (float) $customer->credit_limit - (float) $customer->current_debt);
            @endphp
            <div class="rounded-xl border border-green-200 bg-green-50 p-4 shadow-sm">
                <p class="text-xs text-green-700 uppercase tracking-wide">Доступно для закупівлі</p>
                <p class="mt-1 text-2xl font-bold text-green-700">
                    {{ number_format($available, 2, ',', ' ') }} ₴
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Термін відстрочки</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">
                    {{ $customer->payment_delay_days ?? 0 }} дн.
                </p>
            </div>
        </div>
    </div>

    {{-- C.3 Recent orders --}}
    <div>
        <h2 class="text-base font-semibold text-gray-900 mb-3">Останні замовлення</h2>

        @if($recentOrders->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-400 text-sm">
                Замовлень ще немає
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wide border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3">№ / Дата</th>
                            <th class="px-4 py-3 text-right">Сума</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3 text-right">Дії</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach($recentOrders as $order)
                            @php
                                $statusColor = match($order->status->value) {
                                    'new'         => 'bg-blue-100 text-blue-800',
                                    'pending'     => 'bg-yellow-100 text-yellow-800',
                                    'confirmed'   => 'bg-green-100 text-green-800',
                                    'in_progress' => 'bg-primary-100 text-primary-700',
                                    'shipped'     => 'bg-purple-100 text-purple-800',
                                    'delivered'   => 'bg-emerald-100 text-emerald-800',
                                    'cancelled'   => 'bg-red-100 text-red-800',
                                    default       => 'bg-gray-100 text-gray-600',
                                };
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <p class="font-mono font-medium text-gray-900">
                                        #{{ $order->onec_number ?? $order->id }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ $order->created_at->format('d.m.Y') }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900 whitespace-nowrap">
                                    {{ number_format((float) $order->total_with_vat, 2, ',', ' ') }} ₴
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColor }}">
                                        {{ $order->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        wire:click="repeatOrder({{ $order->id }})"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 transition-colors"
                                        title="Додати позиції цього замовлення до кошика"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                        </svg>
                                        Повторити
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
