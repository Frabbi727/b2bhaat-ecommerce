<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\ProductDailyView;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Reporting\SellerInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\SellerAccounting\Concerns\BuildsSellerBooksFixtures;
use Tests\TestCase;

class SellerSalesInsightsServiceTest extends TestCase
{
    use BuildsSellerBooksFixtures;
    use RefreshDatabase;

    private SellerInsightsService $insights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSellerBooksPrerequisites();
        $this->insights = app(SellerInsightsService::class);
    }

    /**
     * A sub-order placed at a given moment (UTC string, or null for now).
     *
     * @param  list<array{product: Product, quantity: int, unit_price: float}>  $items
     */
    private function sale(User $seller, array $items, ?string $at = null, string $fulfillment = 'delivered', ?User $buyer = null): SubOrder
    {
        $subOrder = $this->makeSubOrder($seller, $items, commission: 0, fulfillment: $fulfillment, buyer: $buyer);

        if ($at !== null) {
            $subOrder->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
        }

        return $subOrder;
    }

    private function today(): string
    {
        return now(config('app.display_timezone'))->toDateString();
    }

    private function daysAgo(int $days): string
    {
        return now(config('app.display_timezone'))->subDays($days)->toDateString();
    }

    public function test_summary_counts_only_this_sellers_non_cancelled_orders(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Saree', 100);
        $this->sale($seller, [['product' => $product, 'quantity' => 2, 'unit_price' => 500]]);
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 3000]]);
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 9999]], fulfillment: 'cancelled');
        $this->sale($other, [['product' => $this->makeProduct($other, 'Lungi', 100), 'quantity' => 1, 'unit_price' => 5000]]);

        $summary = $this->insights->summary($seller->id, $this->daysAgo(29), $this->today());

        $this->assertSame(4000.0, $summary['revenue']['value']);
        $this->assertSame(2, $summary['orders']['value']);
        $this->assertSame(2000.0, $summary['average_order_value']['value']);
        $this->assertSame(3, $summary['units']['value']);
    }

    public function test_summary_change_vs_previous_period(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Saree', 100);
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 1500]], '2026-11-15 06:00:00');
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 1000]], '2026-11-05 06:00:00');
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 7000]], '2026-10-25 06:00:00');

        $summary = $this->insights->summary($seller->id, '2026-11-11', '2026-11-20');

        $this->assertSame(1500.0, $summary['revenue']['value']);
        $this->assertSame(50.0, $summary['revenue']['change']);
        $this->assertSame(0.0, $summary['orders']['change']);
    }

    public function test_this_month_is_capped_at_today_and_compared_with_same_number_of_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Saree', 100);
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 300]], '2026-11-02 06:00:00');
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 300]], '2026-10-30 06:00:00');
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 5000]], '2026-10-15 06:00:00');

        $summary = $this->insights->summary($seller->id, '2026-11-01', '2026-11-30');

        $this->assertSame(300.0, $summary['revenue']['value']);
        $this->assertSame(0.0, $summary['revenue']['change']);
    }

    public function test_change_is_null_without_a_start_date(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Saree', 100);
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 300]]);

        foreach ([[null, null], [null, $this->today()]] as [$from, $to]) {
            $summary = $this->insights->summary($seller->id, $from, $to);

            $this->assertSame(300.0, $summary['revenue']['value']);
            foreach ($summary as $figure) {
                $this->assertNull($figure['change']);
            }
        }
    }

    public function test_dhaka_day_boundaries_decide_which_period_an_order_is_in(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Saree', 100);
        // 18:30 UTC on 10 Nov is 00:30 on 11 Nov in Dhaka.
        $this->sale($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 800]], '2026-11-10 18:30:00');

        $this->assertSame(800.0, $this->insights->summary($seller->id, '2026-11-11', '2026-11-11')['revenue']['value']);
        $this->assertSame(0.0, $this->insights->summary($seller->id, '2026-11-10', '2026-11-10')['revenue']['value']);
    }

    public function test_channel_split_with_a_mixed_sub_order(): void
    {
        $seller = $this->makeSeller();
        $retail = $this->makeProduct($seller, 'Retail Saree', 100);
        $wholesale = $this->makeProduct($seller, 'Bulk Saree', 100, SalesChannel::WHOLESALE);
        $this->sale($seller, [
            ['product' => $retail, 'quantity' => 1, 'unit_price' => 600],
            ['product' => $wholesale, 'quantity' => 1, 'unit_price' => 400],
        ]);
        $this->sale($seller, [['product' => $retail, 'quantity' => 1, 'unit_price' => 1000]]);

        $split = $this->insights->channelSplit($seller->id, $this->daysAgo(29), $this->today());

        $this->assertSame(['revenue' => 1600.0, 'orders' => 2, 'share' => 80.0], $split['retail']);
        $this->assertSame(['revenue' => 400.0, 'orders' => 1, 'share' => 20.0], $split['wholesale']);
        $this->assertSame(1, $split['mixed_orders']);
    }

    public function test_channel_split_with_no_sales_has_zero_shares(): void
    {
        $seller = $this->makeSeller();

        $split = $this->insights->channelSplit($seller->id, $this->daysAgo(29), $this->today());

        $this->assertSame(['revenue' => 0.0, 'orders' => 0, 'share' => 0.0], $split['retail']);
        $this->assertSame(0, $split['mixed_orders']);
    }

    private function liveProduct(User $seller, string $title, string $channel = SalesChannel::RETAIL): Product
    {
        $product = $this->makeProduct($seller, $title, 100, $channel);
        $product->forceFill(['approval_status' => 'approved', 'approved_at' => now()])->save();

        return $product;
    }

    private function views(Product $product, string $date, int $views): void
    {
        ProductDailyView::factory()->for($product)->create(['date' => $date, 'views' => $views]);
    }

    public function test_top_products_ranked_by_revenue_with_conversion(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $x = $this->makeProduct($seller, 'Product X', 100);
        $y = $this->makeProduct($seller, 'Product Y', 100);
        $this->sale($seller, [['product' => $x, 'quantity' => 1, 'unit_price' => 2000]]);
        $this->sale($seller, [['product' => $x, 'quantity' => 1, 'unit_price' => 3000], ['product' => $y, 'quantity' => 1, 'unit_price' => 1000]]);
        $this->sale($other, [['product' => $this->makeProduct($other, 'Foreign Product', 100), 'quantity' => 1, 'unit_price' => 90000]]);
        $this->views($x, $this->daysAgo(2), 60);
        $this->views($x, $this->today(), 40);
        $this->views($x, $this->daysAgo(40), 50);

        $top = $this->insights->topProducts($seller->id, $this->daysAgo(29), $this->today());

        $this->assertSame(['Product X', 'Product Y'], array_column($top, 'title'));
        $this->assertSame(5000.0, $top[0]['revenue']);
        $this->assertSame(2, $top[0]['units']);
        $this->assertSame(2, $top[0]['orders']);
        $this->assertSame(100, $top[0]['views']);
        $this->assertSame(2.0, $top[0]['conversion']);
        $this->assertSame(0, $top[1]['views']);
        $this->assertNull($top[1]['conversion']);
    }

    public function test_top_products_split_revenue_by_channel(): void
    {
        $seller = $this->makeSeller();
        $retail = $this->makeProduct($seller, 'Saree', 100);
        $this->sale($seller, [['product' => $retail, 'quantity' => 1, 'unit_price' => 700]]);
        $subOrder = $this->sale($seller, [['product' => $retail, 'quantity' => 1, 'unit_price' => 300]]);
        $subOrder->items()->update(['sales_channel_id' => SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id')]);

        $row = $this->insights->topProducts($seller->id, $this->daysAgo(29), $this->today())[0];

        $this->assertSame(1000.0, $row['revenue']);
        $this->assertSame(700.0, $row['retail_revenue']);
        $this->assertSame(300.0, $row['wholesale_revenue']);
    }

    public function test_viewed_not_selling_uses_thresholds(): void
    {
        $seller = $this->makeSeller();
        $a = $this->liveProduct($seller, 'A low converter');
        $b = $this->liveProduct($seller, 'B few views');
        $c = $this->liveProduct($seller, 'C healthy');
        $d = $this->makeProduct($seller, 'D draft', 100);
        $this->views($a, $this->today(), 200);
        $this->views($b, $this->today(), 19);
        $this->views($c, $this->today(), 50);
        $this->views($d, $this->today(), 300);
        $this->sale($seller, [['product' => $a, 'quantity' => 1, 'unit_price' => 100]]);
        $this->sale($seller, [['product' => $c, 'quantity' => 1, 'unit_price' => 100]]);

        $rows = $this->insights->viewedNotSelling($seller->id, $this->daysAgo(29), $this->today());

        $this->assertSame(['A low converter'], array_column($rows, 'title'));
        $this->assertSame(200, $rows[0]['views']);
        $this->assertSame(1, $rows[0]['orders']);
        $this->assertSame(0.5, $rows[0]['conversion']);
    }

    public function test_not_sold_recently_lists_never_sold_first_then_oldest_sale(): void
    {
        $seller = $this->makeSeller();
        $p = $this->liveProduct($seller, 'P sold long ago');
        $q = $this->liveProduct($seller, 'Q never sold');
        $r = $this->liveProduct($seller, 'R sold lately');
        $this->sale($seller, [['product' => $p, 'quantity' => 1, 'unit_price' => 100]], now()->subDays(40)->toDateTimeString());
        $this->sale($seller, [['product' => $r, 'quantity' => 1, 'unit_price' => 100]], now()->subDays(5)->toDateTimeString());

        $rows = $this->insights->notSoldRecently($seller->id);

        $this->assertSame(['Q never sold', 'P sold long ago'], array_column($rows, 'title'));
        $this->assertNull($rows[0]['last_sold_on']);
        $this->assertSame(now(config('app.display_timezone'))->subDays(40)->toDateString(), $rows[1]['last_sold_on']);
    }

    public function test_views_counted_since_is_the_first_logged_day(): void
    {
        $this->assertNull($this->insights->viewsCountedSince());

        $seller = $this->makeSeller();
        $this->views($this->makeProduct($seller, 'Saree', 100), '2026-11-10', 3);
        $this->views($this->makeProduct($seller, 'Lungi', 100), '2026-11-08', 3);

        $this->assertSame('2026-11-08', $this->insights->viewsCountedSince());
    }
}
