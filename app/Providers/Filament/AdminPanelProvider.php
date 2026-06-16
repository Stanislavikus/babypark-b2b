<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('BabyPark B2B')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString(<<<'HTML'
<!-- BabyPark product-photo lightbox — pure JS, no Alpine/Livewire dependencies -->
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
HTML)
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
