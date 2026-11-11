<?php

namespace App\Providers\Filament;

use App\Filament\Seller\Widgets\SellerRecentOrdersWidget;
use App\Filament\Seller\Widgets\SellerRevenueChart;
use App\Filament\Seller\Widgets\SellerStatsOverview;
use App\Filament\Seller\Widgets\SellerStockAlertsWidget;
use App\Http\Middleware\AuditRequestContextMiddleware;
use App\Services\Settings\StoreConfigurationService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SellerPanelProvider extends PanelProvider
{
    use ConfiguresPanelDesign;

    public function panel(Panel $panel): Panel
    {
        $panel = $this->applyPanelDesign($panel, Color::Emerald);

        return $panel
            ->id('seller')
            ->brandName(fn (): string => app(StoreConfigurationService::class)->getStoreIdentity()['name'].' Seller Center')
            ->brandLogo(fn (): View => view('filament.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon-32x32.png'))
            ->path('seller')
            ->viteTheme('resources/css/filament/seller/theme.css')
            ->login()
            ->databaseNotifications()
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Accounts'),
                NavigationGroup::make('Store'),
            ])
            ->navigationItems([
                NavigationItem::make('View my storefront')
                    ->url(fn (): string => auth()->user()?->sellerProfile?->store_slug ? route('store.show', auth()->user()->sellerProfile->store_slug) : '/', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->group('Store')
                    ->sort(99),
            ])
            ->discoverResources(in: app_path('Filament/Seller/Resources'), for: 'App\Filament\Seller\Resources')
            ->discoverPages(in: app_path('Filament/Seller/Pages'), for: 'App\Filament\Seller\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Seller/Widgets'), for: 'App\Filament\Seller\Widgets')
            ->widgets([
                SellerStatsOverview::class,
                SellerRevenueChart::class,
                SellerStockAlertsWidget::class,
                SellerRecentOrdersWidget::class,
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
                AuditRequestContextMiddleware::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
