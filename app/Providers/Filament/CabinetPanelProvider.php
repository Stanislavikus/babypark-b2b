<?php

namespace App\Providers\Filament;

use App\Filament\Cabinet\Pages\Auth\Login;
use App\Support\Brand;
use App\Support\ProductLightbox;
use App\Support\SessionCart;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CabinetPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cabinet')
            ->path('cabinet')
            ->authGuard('contractor')
            ->login(Login::class)
            ->brandName('BabyPark B2B')
            ->colors([
                'primary' => Brand::primaryColor(),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => ProductLightbox::bodyEndHook()
            )
            ->discoverResources(in: app_path('Filament/Cabinet/Resources'), for: 'App\\Filament\\Cabinet\\Resources')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->navigationItems([
                NavigationItem::make('Кошик')
                    ->icon('heroicon-o-shopping-cart')
                    ->sort(10)
                    ->badge(fn (): ?string => ($count = SessionCart::count()) > 0 ? (string) $count : null),
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
