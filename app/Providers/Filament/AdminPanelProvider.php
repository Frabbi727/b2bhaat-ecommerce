<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Resources\AuditLogs\Widgets\AuditLogStatsWidget;
use App\Filament\Admin\Resources\ChangeRequestApprovals\ChangeRequestApprovalResource;
use App\Filament\Admin\Resources\ProductApprovals\ProductApprovalResource;
use App\Filament\Admin\Widgets\AdminFulfillmentDistributionChart;
use App\Filament\Admin\Widgets\AdminOrdersRevenueChart;
use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Admin\Widgets\LatestOrdersWidget;
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

class AdminPanelProvider extends PanelProvider
{
    use ConfiguresPanelDesign;

    public function panel(Panel $panel): Panel
    {
        $panel = $this->applyPanelDesign($panel, Color::Indigo);

        return $panel
            ->default()
            ->id('admin')
            ->brandName(fn (): string => app(StoreConfigurationService::class)->getStoreIdentity()['name'].' Admin')
            ->brandLogo(fn (): View => view('filament.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon-32x32.png'))
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->navigationGroups([
                NavigationGroup::make('Orders & Fulfilment'),
                NavigationGroup::make('Sellers & Customers'),
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Accounting'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Security & Audit'),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->databaseNotifications()
            ->navigationItems([
                // The parent the two queues sit under; Filament drops child items whose parent does not exist, and shows children only for a parent without a link.
                NavigationItem::make('Approvals')
                    ->group('Catalog')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->sort(1)
                    ->badge(fn (): ?string => ($total = (int) ProductApprovalResource::getNavigationBadge() + (int) ChangeRequestApprovalResource::getNavigationBadge()) > 0 ? (string) $total : null)
                    ->visible(fn (): bool => auth()->user()?->isCatalogReviewer() === true),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AdminStatsOverview::class,
                AdminOrdersRevenueChart::class,
                AdminFulfillmentDistributionChart::class,
                LatestOrdersWidget::class,
                AuditLogStatsWidget::class,
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
