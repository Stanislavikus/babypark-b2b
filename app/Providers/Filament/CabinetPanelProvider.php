<?php

namespace App\Providers\Filament;

use App\Filament\Cabinet\Pages\Auth\Login;
use App\Filament\Cabinet\Resources\ProductResource\Pages\ListProducts;
use App\Support\Brand;
use App\Support\FilamentTableToolbar;
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
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CabinetPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cabinet')
            ->path('cabinet')
            ->authGuard('customer')
            ->login(Login::class)
            ->brandName('BabyPark B2B')
            ->colors([
                'primary' => Brand::primaryColor(),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => ProductLightbox::bodyEndHook()
            )
            ->renderHook(
                FilamentTableToolbar::stylesHookName(),
                FilamentTableToolbar::stylesRenderHook()
            )
            ->renderHook(
                TablesRenderHook::TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER,
                fn (): string => Blade::render('@livewire(\'cabinet.cart-toolbar\')'),
                ListProducts::class,
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
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
