<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Models\Product;
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
}
