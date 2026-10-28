<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Admin\Widgets\LatestOrdersWidget;
use App\Filament\Seller\Pages\StoreSettings;
use App\Filament\Seller\Resources\ProductReviews\Pages\ListProductReviews;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Filament\Seller\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Filament\Seller\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Filament\Seller\Resources\SellerWallets\Pages\ListSellerWallets;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Filament\Seller\Widgets\SellerRecentOrdersWidget;
use App\Filament\Seller\Widgets\SellerStatsOverview;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPanelsSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $sellerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->adminUser = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->sellerUser = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
    }

    public function test_admin_dashboard_and_widgets(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->adminUser);

        $this->get('/admin')->assertSuccessful();

        Livewire::test(AdminStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('Platform Settled GMV')
            ->assertSee('Active Escrow Reserve');

        Livewire::test(LatestOrdersWidget::class)
            ->assertSuccessful();
    }

    public function test_admin_resources_tables_render(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->adminUser);

        Livewire::test(ListCategories::class)->assertSuccessful();
        Livewire::test(ListOrders::class)->assertSuccessful();
        Livewire::test(ListSellerProfiles::class)->assertSuccessful();
        Livewire::test(ListUsers::class)->assertSuccessful();
        Livewire::test(ListWalletTransactions::class)->assertSuccessful();

        $this->get('/admin/categories/create')->assertSuccessful();
        $this->get('/admin/orders/create')->assertSuccessful();
        $this->get('/admin/seller-profiles/create')->assertSuccessful();
        $this->get('/admin/users/create')->assertSuccessful();
    }

    public function test_seller_resources_tables_render(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->sellerUser);

        $this->get('/seller')->assertSuccessful();

        Livewire::test(SellerStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('Available Payout Balance')
            ->assertSee('Escrow In-Transit Reserve');

        Livewire::test(SellerRecentOrdersWidget::class)
            ->assertSuccessful();

        Livewire::test(ListProducts::class)->assertSuccessful();
        Livewire::test(ListProductReviews::class)->assertSuccessful();
        Livewire::test(ListReturnRequests::class)->assertSuccessful();
        Livewire::test(ListRfqRequests::class)->assertSuccessful();
        Livewire::test(ListSellerWallets::class)->assertSuccessful();
        Livewire::test(ListSubOrders::class)->assertSuccessful();
        Livewire::test(StoreSettings::class)->assertSuccessful();

        $this->get('/seller/products/create')->assertSuccessful();
        $this->get('/seller/rfq-requests/create')->assertSuccessful();
        $this->get('/seller/sub-orders/create')->assertSuccessful();
    }
}
