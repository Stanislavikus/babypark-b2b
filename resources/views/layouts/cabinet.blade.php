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

<!-- BabyPark product-photo lightbox — same component as admin panel (AdminPanelProvider) -->
<div id="bp-photo-lb"
     onclick="if(event.target===this||event.target.closest('.bp-lb-close'))document.getElementById('bp-photo-lb').style.display='none'"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.72);align-items:center;justify-content:center;padding:24px;">
    <div style="position:relative;max-width:min(800px,90vw);background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.4);">
        <button class="bp-lb-close"
                aria-label="Закрити"
                style="position:absolute;top:8px;right:8px;z-index:1;background:#fff;border:1px solid #e5e7eb;border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;line-height:1;color:#6b7280;">×</button>
        <div id="bp-photo-lb-title"
             style="padding:12px 48px 12px 16px;font-weight:600;font-size:15px;border-bottom:1px solid #f3f4f6;color:#111827;min-height:44px;"></div>
        <div style="padding:16px;display:flex;justify-content:center;">
            <img id="bp-photo-lb-img"
                 src=""
                 alt=""
                 style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:6px;">
        </div>
    </div>
</div>
<script>
function bpOpenLightbox(src, title) {
    var lb = document.getElementById('bp-photo-lb');
    document.getElementById('bp-photo-lb-img').src = src;
    document.getElementById('bp-photo-lb-title').textContent = title || '';
    lb.style.display = 'flex';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('bp-photo-lb').style.display = 'none';
});
</script>
</body>
</html>
