<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * PRA-051: the courier must collect goods + delivery fee − this consignment's share of the coupon.
 */
class CodCollectionAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_discount_is_allocated_by_subtotal_share(): void
    {
        $order = MixedOrderFixture::create();
        [$a, $b] = [$order->subOrders->firstWhere('subtotal', 1000), $order->subOrders->firstWhere('subtotal', 500)];

        // 50 × 1000/1500 = 33.33 (+ rounding remainder on the largest), 50 × 500/1500 = 16.67
        $this->assertEqualsWithDelta(33.33, $a->allocatedDiscount(), 0.001);
        $this->assertEqualsWithDelta(16.67, $b->allocatedDiscount(), 0.001);
        $this->assertEqualsWithDelta(1036.67, $a->paidAmount(), 0.001);
        $this->assertEqualsWithDelta(553.33, $b->paidAmount(), 0.001);
    }

    public function test_sum_of_cod_amounts_equals_order_total(): void
    {
        $order = MixedOrderFixture::create();

        $sum = $order->subOrders->sum(fn (SubOrder $subOrder) => $subOrder->codCollectAmount());

        $this->assertEqualsWithDelta((float) $order->total_amount, $sum, 0.001);
    }

    public function test_rounding_remainder_goes_to_largest_sub_order(): void
    {
        $order = MixedOrderFixture::create();
        $a = $order->subOrders->firstWhere('subtotal', 1000);
        $b = $order->subOrders->firstWhere('subtotal', 500);
        // Three equal consignments of ৳500 and a ৳1 coupon: 0.333… each → 0.33 + 0.33 + remainder 0.34
        $a->update(['subtotal' => 500.00]);
        $c = $b->replicate();
        $c->sub_order_number = $b->sub_order_number.'-C';
        $c->tracking_number = null;
        $c->save();
        $order->update(['discount_amount' => 1.00]);
        $order->refresh();

        $shares = $order->subOrders->sortBy('id')->map(fn (SubOrder $subOrder) => $subOrder->allocatedDiscount())->values()->all();

        $this->assertEqualsWithDelta(1.00, array_sum($shares), 0.0001);
        $this->assertEqualsWithDelta(0.34, $shares[0], 0.0001, 'Tie on largest subtotal: remainder goes to the lowest id');
        $this->assertEqualsWithDelta(0.33, $shares[1], 0.0001);
        $this->assertEqualsWithDelta(0.33, $shares[2], 0.0001);
    }

    public function test_prepaid_order_collects_nothing_but_still_allocates_discount(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        $b = $order->subOrders->firstWhere('subtotal', 500);

        $this->assertSame(0.0, $b->codCollectAmount());
        $this->assertEqualsWithDelta(16.67, $b->allocatedDiscount(), 0.001);
    }

    public function test_challan_shows_cod_amount_including_delivery_fee(): void
    {
        $subOrder = SubOrder::where('sub_order_number', 'PKG-20261028-001')->firstOrFail();
        $subOrder->order->update([
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->value('id'),
        ]);
        $seller = User::findOrFail($subOrder->seller_id);

        $this->actingAs($seller)
            ->get(route('seller.sub_order.challan', $subOrder->sub_order_number))
            ->assertOk()
            ->assertSee('৳570.00');

        $this->actingAs($seller)
            ->get(route('seller.sub_order.label', $subOrder->sub_order_number))
            ->assertOk()
            ->assertSee('৳570.00');
    }
}
