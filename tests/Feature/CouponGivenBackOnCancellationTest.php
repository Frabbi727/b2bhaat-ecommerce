<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\User;
use App\Services\Cart\CouponService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Payments\PaymentVerificationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 3.9 (AR-04, owner decision 2026-11-19): an order that ends fully cancelled (buyer, seller, admin, or a
 * failed/expired payment) gives its coupon use back. A partial cancellation or a return after delivery keeps it.
 */
class CouponGivenBackOnCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private Coupon $coupon;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->order = MixedOrderFixture::create('cod', 'unpaid');
        $this->order->subOrders()->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $this->buyer = $this->order->customer;

        $this->coupon = Coupon::create([
            'code' => 'ONCE50', 'name' => 'Once only', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 50,
            'min_order_amount' => 0, 'usage_limit_total' => 1, 'usage_limit_per_user' => 1, 'used_count' => 1, 'is_active' => true,
        ]);
        $this->order->update(['coupon_id' => $this->coupon->id, 'coupon_code' => 'ONCE50']);
        CouponUsage::create(['coupon_id' => $this->coupon->id, 'user_id' => $this->buyer->id, 'order_id' => $this->order->id, 'discount_amount' => 50]);
    }

    private function cancelEveryParcel(): void
    {
        app(OrderDisputeService::class)->cancelFullOrder($this->order, 'Changed mind');
    }

    private function buyerCanUseItAgain(): bool
    {
        return $this->coupon->fresh()->validateForSubtotal(1000, $this->buyer->id)['valid'];
    }

    public function test_a_fully_cancelled_order_gives_the_coupon_use_back(): void
    {
        $this->assertFalse($this->buyerCanUseItAgain());

        $this->cancelEveryParcel();

        $this->assertSame(0, $this->coupon->fresh()->used_count);
        $this->assertNotNull(CouponUsage::where('order_id', $this->order->id)->value('reversed_at'));
        $this->assertTrue($this->buyerCanUseItAgain());
    }

    public function test_cancelling_one_parcel_keeps_the_coupon_used(): void
    {
        app(OrderDisputeService::class)->cancelSubOrder($this->order->subOrders()->firstOrFail(), 'Changed mind');

        $this->assertSame(1, $this->coupon->fresh()->used_count);
        $this->assertFalse($this->buyerCanUseItAgain());
    }

    public function test_a_rejected_payment_gives_the_coupon_use_back(): void
    {
        $this->order->update([
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'awaiting_verification')->value('id'),
        ]);

        app(PaymentVerificationService::class)->rejectPayment($this->order, 'TrxID not found');

        $this->assertSame(0, $this->coupon->fresh()->used_count);
        $this->assertTrue($this->buyerCanUseItAgain());
    }

    public function test_a_returned_order_keeps_the_coupon_used(): void
    {
        foreach ($this->order->subOrders()->get() as $parcel) {
            $parcel->update(['tracking_number' => 'TRK-'.$parcel->id]);
            app(CourierStatusService::class)->updateStatus($parcel, 'shipped');
            app(CourierStatusService::class)->updateStatus($parcel, 'returned');
        }

        $this->assertSame('cancelled', $this->order->fresh()->orderStatus?->slug);
        $this->assertSame(1, $this->coupon->fresh()->used_count, 'the buyer got the goods; the use is spent');
    }

    public function test_the_use_is_given_back_only_once(): void
    {
        $this->cancelEveryParcel();
        app(CouponService::class)->releaseForOrder($this->order);

        $this->assertSame(0, $this->coupon->fresh()->used_count);
    }
}
