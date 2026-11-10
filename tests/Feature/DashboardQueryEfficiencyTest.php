<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Widgets\AdminFulfillmentDistributionChart;
use App\Filament\Admin\Widgets\AdminOrdersRevenueChart;
use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Seller\Widgets\SellerRevenueChart;
use App\Filament\Seller\Widgets\SellerStatsOverview;
use App\Models\Order;
use App\Models\PaymentStatus;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PERF-2: dashboards don't re-run their aggregate queries every 5 seconds for every open tab, the stats use fewer
 * queries, and the charts read only the two columns they need and count days in Dhaka time.
 */
class DashboardQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_dashboard_widgets_refresh_once_a_minute_not_every_five_seconds(): void
    {
        foreach ([AdminStatsOverview::class, AdminOrdersRevenueChart::class, AdminFulfillmentDistributionChart::class, SellerStatsOverview::class, SellerRevenueChart::class] as $widget) {
            $method = new ReflectionMethod($widget, 'getPollingInterval');
            $this->assertSame('60s', $method->invoke(new $widget), $widget);
        }
    }

    public function test_admin_stats_run_fewer_queries(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        DB::enableQueryLog();
        Livewire::test(AdminStatsOverview::class)->assertSuccessful();

        $this->assertLessThanOrEqual(9, count(DB::getQueryLog()));
    }

    public function test_the_revenue_chart_counts_days_in_dhaka_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $paid = PaymentStatus::where('slug', 'paid')->firstOrFail();

        $order = Order::firstOrFail();
        // 2026-11-08 20:00 UTC is 2026-11-09 02:00 in Dhaka
        $order->forceFill(['payment_status_id' => $paid->id, 'total_amount' => 777, 'created_at' => Carbon::parse('2026-11-08 20:00:00', 'UTC')])->save();
        Order::whereKeyNot($order->id)->update(['created_at' => Carbon::parse('2026-01-01')]);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $data = (new ReflectionMethod(AdminOrdersRevenueChart::class, 'getData'))->invoke(new AdminOrdersRevenueChart);
        $byLabel = array_combine($data['labels'], $data['datasets'][0]['data']);

        $this->assertSame(777.0, $byLabel['09 Nov']);
        $this->assertSame(0.0, $byLabel['08 Nov']);
        $this->assertSame('10 Nov', end($data['labels']));
    }
}
