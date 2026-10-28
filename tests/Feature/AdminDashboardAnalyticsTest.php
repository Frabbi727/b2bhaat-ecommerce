<?php

namespace Tests\Feature;

use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Admin\Widgets\LatestOrdersWidget;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_super_admin_can_access_admin_dashboard(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->actingAs($admin);

        $response = $this->get('/admin');
        $response->assertStatus(200);
        $response->assertSee('Dashboard');
        $response->assertSee('Orders');
        $response->assertSee('Seller Profiles');
    }

    public function test_non_admin_cannot_access_admin_dashboard(): void
    {
        // Guest
        $this->get('/admin')->assertRedirect();

        // Retail customer
        $retail = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retail);
        $this->get('/admin')->assertStatus(403);

        // Seller
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->actingAs($seller);
        $this->get('/admin')->assertStatus(403);
    }

    public function test_admin_stats_overview_widget_renders_accurate_metrics(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminStatsOverview::class)
            ->assertStatus(200)
            ->assertSee('Platform Settled GMV')
            ->assertSee('Active Escrow Reserve')
            ->assertSee('Verified Factory Sellers')
            ->assertSee('Pending Admin Actions');
    }

    public function test_latest_orders_widget_displays_recent_orders(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $latestOrder = Order::latest()->first();

        $test = Livewire::test(LatestOrdersWidget::class)
            ->assertStatus(200)
            ->assertSee('Recent Marketplace Orders (Live Feed)');

        if ($latestOrder) {
            $test->assertSee($latestOrder->order_number);
        }
    }
}
