<!DOCTYPE html>
<html lang="uk" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'BabyPark B2B') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">

@auth('contractor')
<nav class="bg-white border-b border-gray-200 shadow-sm">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <div class="flex items-center gap-6">
                <a href="{{ route('cabinet.catalog') }}" class="text-lg font-bold text-indigo-600">
                    BabyPark B2B
                </a>
                <a href="{{ route('cabinet.dashboard') }}"
                   class="text-sm font-medium transition-colors hover:text-indigo-600 {{ request()->routeIs('cabinet.dashboard') ? 'text-indigo-600' : 'text-gray-700' }}">
                    Кабінет
                </a>
                <a href="{{ route('cabinet.catalog') }}"
                   class="text-sm font-medium transition-colors hover:text-indigo-600 {{ request()->routeIs('cabinet.catalog*') ? 'text-indigo-600' : 'text-gray-700' }}">
                    Каталог
                </a>
            </div>
            <div class="flex items-center gap-4">
                {{-- Cart indicator --}}
                @livewire('cabinet.cart-indicator')

                <span class="text-sm text-gray-600">
                    {{ auth('contractor')->user()->short_name ?? auth('contractor')->user()->name }}
                </span>
                <form method="POST" action="{{ route('cabinet.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-red-600 transition-colors">
                        Вийти
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
@endauth

<main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">
    {{ $slot }}
</main>

@livewireScripts
</body>
</html>
