<?php

declare(strict_types=1);

namespace Tests\Feature\WholesaleAudit;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Coupon;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Cart\CartSessionService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wholesale audit 2026-11-14, money-flow scenarios (report D §6). Expected values were worked by hand in poisha
 * before the test was run; each assertion compares the stored amount with that hand figure.
 */
#[Group('wholesale-audit')]
class MoneyFlowProbeTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        app(CartSessionService::class)->clear();
    }

    /**
     * @param  array<int, array{0: int, 1: ?int, 2: string}>  $tiers  [min, max, price] wholesale rows
     */
    private function priceProduct(string $retail, array $tiers): void
    {
        $retailId = SalesChannel::where('slug', SalesChannel::RETAIL)->value('id');
        $wholesaleId = SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id');

        ProductPrice::where('product_id', $this->product->id)->delete();
        ProductPrice::create(['product_id' => $this->product->id, 'sales_channel_id' => $retailId, 'min_quantity' => 1, 'unit_price' => $retail]);
        foreach ($tiers as [$min, $max, $price]) {
            ProductPrice::create(['product_id' => $this->product->id, 'sales_channel_id' => $wholesaleId, 'min_quantity' => $min, 'max_quantity' => $max, 'unit_price' => $price]);
        }
    }

    private function fixDeliveryFee(string $insideDhaka): void
    {
        $this->product->seller->sellerProfile->forceFill([
            'inside_dhaka_rate' => $insideDhaka,
            'free_shipping_threshold' => 10000000,
        ])->save();
    }

    private function checkout(): Order
    {
        $this->actingAs($this->buyer);
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Boutique Buyer')
            ->set('recipientPhone', '01711000000')
            ->set('streetAddress', 'House 1, Road 2, Gulshan')
            ->set('city', 'Dhaka')
            ->call('placeOrder')
            ->assertSet('errorMessage', '');

        return Order::where('customer_id', $this->buyer->id)->latest('id')->with('subOrders.items')->firstOrFail();
    }

    private function poisha(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * S1 (brief §13, adapted): retail ৳1,000, wholesale ৳850 from 10 units, 50 units, 5% coupon, delivery ৳200.
     * Hand figures: subtotal 4,250,000 p; coupon 212,500 p; delivery 20,000 p; payable 4,057,500 p.
     * Commission at the seller's stored rate r: round(4,250,000 × r / 100).
     */
    public function test_wa_mny_s1_brief_example(): void
    {
        $this->priceProduct('1000.00', [[10, null, '850.00']]);
        $this->fixDeliveryFee('200.00');
        Coupon::create(['code' => 'AUDIT5', 'name' => 'Audit 5%', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 5, 'min_order_amount' => 0, 'is_active' => true]);

        $this->actingAs($this->buyer);
        $cart = app(CartSessionService::class);
        $variation = $this->product->variations()->orderBy('id')->firstOrFail();
        $cart->addWholesaleLot($this->product->id, [$variation->id => 50]);
        $this->assertTrue($cart->applyCoupon('AUDIT5', $this->buyer->id)['valid']);

        $order = $this->checkout();
        $sub = $order->subOrders->sole();
        $item = $sub->items->sole();
        $rate = (float) $item->commission_rate;

        $this->assertSame(85000, $this->poisha($item->unit_price));
        $this->assertSame(4250000, $this->poisha($sub->subtotal));
        $this->assertSame(212500, $this->poisha($order->discount_amount));
        $this->assertSame(20000, $this->poisha($order->shipping_amount));
        $this->assertSame(20000, $this->poisha($sub->shipping_amount));
        $this->assertSame(4057500, $this->poisha($order->total_amount));
        $this->assertSame((int) round(4250000 * $rate / 100), $this->poisha($sub->commission_amount));
        $this->assertSame(4057500, $this->poisha($sub->paidAmount()), 'parcel paid = goods + delivery − coupon share');
        $this->assertSame(4250000 - (int) round(4250000 * $rate / 100), $this->poisha($sub->net_earnings), 'coupon is platform-funded: seller net ignores it');
        $this->assertSame(10.0, $rate, 'demo seller rate (no plan): 10%');
    }

    /**
     * S2: tier boundaries 9/10, 49/50, 99/100 with tiers 10–49 @ ৳850, 50–99 @ ৳820, 100+ @ ৳800, stored on the order.
     * The COD limit is raised to ৳1,000,000 so 100 units (৳80,000) can be placed as COD.
     */
    public function test_verified_mny_s2_tier_boundaries_reach_the_order(): void
    {
        $this->priceProduct('1000.00', [[10, 49, '850.00'], [50, 99, '820.00'], [100, null, '800.00']]);
        app(StoreConfigurationService::class)->set('cod_max_order_limit', 1000000, 'checkout', 'float');
        $this->fixDeliveryFee('200.00');
        $variation = $this->product->variations()->orderBy('id')->firstOrFail();
        $this->actingAs($this->buyer);
        $cart = app(CartSessionService::class);

        try {
            $cart->addWholesaleLot($this->product->id, [$variation->id => 9]);
            $this->fail('9 is below the MOQ of 10');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('minimum order quantity', $exception->getMessage());
        }

        foreach ([10 => 85000, 49 => 85000, 50 => 82000, 99 => 82000, 100 => 80000] as $quantity => $unitPoisha) {
            $cart->clear();
            $cart->addWholesaleLot($this->product->id, [$variation->id => $quantity]);
            $item = $this->checkout()->subOrders->sole()->items->sole();
            $this->assertSame($unitPoisha, $this->poisha($item->unit_price), "unit price at {$quantity}");
            $this->assertSame($unitPoisha * $quantity, $this->poisha($item->total_price), "line total at {$quantity}");
        }
    }

    /**
     * S3: two sellers, ৳0.01 coupon on two equal ৳50.00 parcels. Rounding each share gives 0.01 + 0.01; the
     * remainder rule must make the shares add up to the order discount, and the parcels' paid amounts to the order total.
     */
    public function test_verified_mny_s3_coupon_shares_sum_to_order_discount(): void
    {
        $secondSeller = User::create([
            'role_id' => Role::where('slug', 'seller')->value('id'),
            'name' => 'Second Vendor Ltd',
            'email' => 'audit-seller2@vendor.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        SellerProfile::create(['user_id' => $secondSeller->id, 'store_name' => 'Audit Hub', 'store_slug' => 'audit-hub', 'commission_rate_percentage' => 12.00, 'is_approved' => true]);

        $other = $this->product->replicate(['slug', 'sku']);
        $other->forceFill(['seller_id' => $secondSeller->id, 'slug' => 'audit-other', 'sku' => 'AUDIT-OTHER'])->save();
        $otherVariation = ProductVariation::create(['product_id' => $other->id, 'sku' => 'AUDIT-OTHER-1', 'stock_quantity' => 100, 'is_active' => true]);
        $variation = $this->product->variations()->orderBy('id')->firstOrFail();
        $retailId = SalesChannel::where('slug', SalesChannel::RETAIL)->value('id');
        SellerProfile::whereIn('user_id', [$this->product->seller_id, $secondSeller->id])->update(['free_shipping_threshold' => 0]);

        $order = app(OrderSplitterService::class)->createAndSplitOrder($this->buyer, [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_amount' => 0.0,
            'discount_amount' => 0.01,
            'shipping_address' => ['recipient_name' => 'R', 'recipient_phone' => '01711000000', 'street_address' => 'House 1', 'city' => 'Dhaka'],
            'items' => [
                ['product_id' => $this->product->id, 'variation_id' => $variation->id, 'sales_channel_id' => $retailId, 'quantity' => 1, 'unit_price' => 50.00],
                ['product_id' => $other->id, 'variation_id' => $otherVariation->id, 'sales_channel_id' => $retailId, 'quantity' => 1, 'unit_price' => 50.00],
            ],
        ]);

        $shares = $order->subOrders->map(fn (SubOrder $sub): int => $this->poisha($sub->allocatedDiscount()));
        $this->assertSame(1, $shares->sum(), 'shares add up to the ৳0.01 order discount');
        $this->assertSame(
            $this->poisha($order->total_amount),
            $order->subOrders->sum(fn (SubOrder $sub): int => $this->poisha($sub->paidAmount())),
            'parcels add up to the order total'
        );
    }

    /**
     * WA-MNY-02: current behaviour; the fix inverts this assertion.
     * One parcel with a retail line ৳1,000 at 10% and a wholesale line ৳1,000 at 5%: commission ৳150.
     * Returning the whole retail line should give back its own ৳100; today the give-back is pro rata by value, ৳75,
     * so the seller is left paying ৳75 on a ৳1,000 line whose rate is 5% (৳50).
     */
    public function test_wa_mny_02_partial_return_gives_back_commission_pro_rata_not_at_the_line_rate(): void
    {
        $seller = $this->product->seller;
        $number = 'ORD-AUDIT-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->buyer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'shipping_amount' => 60.00,
            'discount_amount' => 0,
            'total_amount' => 2060.00,
        ]);
        $sub = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'TRK-'.$number,
            'subtotal' => 2000.00,
            'commission_amount' => 150.00,
            'shipping_amount' => 60.00,
        ]);
        [$v1, $v2] = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->take(2)->get()->all();
        $retailLine = OrderItem::create(['sub_order_id' => $sub->id, 'product_id' => $this->product->id, 'variation_id' => $v1->id, 'sales_channel_id' => SalesChannel::where('slug', 'retail')->value('id'), 'unit_price' => 100.00, 'quantity' => 10, 'total_price' => 1000.00, 'commission_rate' => 10.00]);
        OrderItem::create(['sub_order_id' => $sub->id, 'product_id' => $this->product->id, 'variation_id' => $v2->id, 'sales_channel_id' => SalesChannel::where('slug', 'wholesale')->value('id'), 'unit_price' => 20.00, 'quantity' => 50, 'total_price' => 1000.00, 'commission_rate' => 5.00]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($sub);
        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($sub->fresh(), $this->buyer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Retail pieces arrived cracked.',
            'items' => [$retailLine->id => 10],
        ]);
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');

        $line = $request->fresh()->items()->firstOrFail();
        $this->assertSame(7500, $this->poisha($line->commission_amount), 'today: ৳75 given back (pro rata), not the line\'s own ৳100');
        $this->assertSame(92500, $this->poisha($line->seller_amount));
        $this->assertSame(100000, $this->poisha($line->refund_amount), 'buyer refund is right: ৳1,000');
    }

    /**
     * WA-MNY-03 (fixed): the splitter works out delivery itself; the order's shipping is the sum of its parcels', and
     * a caller figure that disagrees is refused instead of being stored.
     */
    public function test_wa_mny_03_order_shipping_is_the_sum_of_its_parcels(): void
    {
        $this->fixDeliveryFee('200.00');
        $variation = $this->product->variations()->orderBy('id')->firstOrFail();
        $payload = [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_address' => ['recipient_name' => 'R', 'recipient_phone' => '01711000000', 'street_address' => 'House 1', 'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka'],
            'items' => [['product_id' => $this->product->id, 'variation_id' => $variation->id, 'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'), 'quantity' => 1, 'unit_price' => 100.00]],
        ];

        try {
            app(OrderSplitterService::class)->createAndSplitOrder($this->buyer, [...$payload, 'shipping_amount' => 0.0]);
            $this->fail('An order was stored with shipping that disagrees with its parcels.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Delivery charge', $e->getMessage());
        }

        $order = app(OrderSplitterService::class)->createAndSplitOrder($this->buyer, $payload);

        $this->assertSame(20000, $this->poisha($order->shipping_amount));
        $this->assertSame(20000, $this->poisha($order->subOrders->sole()->shipping_amount));
        $this->assertSame(30000, $this->poisha($order->total_amount));
        $this->assertSame(30000, $this->poisha($order->subOrders->sole()->paidAmount()));
    }
}
