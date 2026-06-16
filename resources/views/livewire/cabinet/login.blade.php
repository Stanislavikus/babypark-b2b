<div class="flex min-h-[70vh] flex-col justify-center">
    <div class="mx-auto w-full max-w-sm">
        <h2 class="mt-8 text-center text-2xl font-bold leading-9 tracking-tight text-gray-900">
            BabyPark B2B
        </h2>
        <p class="mt-1 text-center text-sm text-gray-500">Вхід до кабінету оптового покупця</p>
    </div>

    <div class="mt-8 mx-auto w-full max-w-sm">
        <div class="bg-white px-6 py-8 shadow rounded-xl border border-gray-200">
            <form wire:submit="authenticate" class="space-y-5">

                @if ($errors->has('login'))
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-700 border border-red-200">
                        {{ $errors->first('login') }}
                    </div>
                @endif

                <div>
                    <label for="login" class="block text-sm font-medium leading-6 text-gray-900">Логін</label>
                    <div class="mt-1">
                        <input
                            type="text"
                            id="login"
                            wire:model="login"
                            autocomplete="username"
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                        >
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium leading-6 text-gray-900">Пароль</label>
                    <div class="mt-1">
                        <input
                            type="password"
                            id="password"
                            wire:model="password"
                            autocomplete="current-password"
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                        >
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="remember" wire:model="remember"
                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
                    <label for="remember" class="text-sm text-gray-700">Запам'ятати мене</label>
                </div>

                <button
                    type="submit"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <span wire:loading.remove>Увійти</span>
                    <span wire:loading>Завантаження…</span>
                </button>
            </form>
        </div>
    </div>
</div>
