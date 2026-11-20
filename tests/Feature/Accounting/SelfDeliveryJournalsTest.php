<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingJournalEntry;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-3 and ACC-4: when a seller delivers the parcel themselves, the books move exactly as the seller's wallet does.
 */
class SelfDeliveryJournalsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private GeneralLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        // A fresh seller, so the demo wallet balances (seeded without journals) don't mix in.
        $this->seller = User::factory()->create(['role_id' => Role::where('slug', 'seller')->value('id')]);
        $this->ledger = app(GeneralLedgerService::class);
    }

    private function makeSubOrder(string $methodSlug, string $paymentSlug, float $commission, float $shipping, float $discount, bool $selfDelivered): SubOrder
    {
        $number = 'ORD-SD-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => User::where('email', 'customer@retail.com')->value('id'),
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $paymentSlug)->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $methodSlug)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'total_amount' => 1000.00 + $shipping - $discount,
            'shipping_amount' => $shipping,
            'discount_amount' => $discount,
        ]);

        return SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
            'courier_name' => $selfDelivered ? null : 'Steadfast Courier',
            'subtotal' => 1000.00,
            'commission_amount' => $commission,
            'shipping_amount' => $shipping,
            'is_self_delivered' => $selfDelivered,
        ]);
    }

    private function wallet(): SellerWallet
    {
        return SellerWallet::where('seller_id', $this->seller->id)->firstOrFail();
    }

    public function test_self_collected_cod_books_the_coupon_share_so_the_ledger_matches_the_wallet(): void
    {
        $subOrder = $this->makeSubOrder('cod', 'unpaid', commission: 100.00, shipping: 70.00, discount: 150.00, selfDelivered: true);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        // The seller kept ৳920 cash but is owed ৳970 (net earnings 900 + fee 70): the platform owes them ৳50.
        $this->assertSame(50.0, (float) $this->wallet()->available_balance);
        $this->assertSame(50.0, $this->ledger->getBalanceForSlug('seller_wallet_payable', sellerId: $this->seller->id));
        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(150.0, $this->ledger->getBalanceForSlug('promotional_discount_expense'));
    }

    public function test_self_collected_cod_with_no_commission_still_posts_the_coupon_share(): void
    {
        $subOrder = $this->makeSubOrder('cod', 'unpaid', commission: 0.00, shipping: 70.00, discount: 100.00, selfDelivered: true);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        $this->assertSame(100.0, (float) $this->wallet()->available_balance);
        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('seller_wallet_payable', sellerId: $this->seller->id));
        $this->assertSame(1, AccountingJournalEntry::where('financial_event', 'cod_self_delivered')->count());
    }

    public function test_self_collected_cod_with_no_commission_and_no_coupon_posts_nothing(): void
    {
        $subOrder = $this->makeSubOrder('cod', 'unpaid', commission: 0.00, shipping: 70.00, discount: 0.00, selfDelivered: true);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        $this->assertSame(0, AccountingJournalEntry::where('financial_event', 'cod_self_delivered')->count());
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('seller_wallet_payable', sellerId: $this->seller->id));
    }

    public function test_switching_a_prepaid_parcel_to_self_delivery_moves_the_fee_to_the_seller_in_the_books(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid', commission: 100.00, shipping: 70.00, discount: 0.00, selfDelivered: false);
        $escrow = app(EscrowLedgerService::class);

        $escrow->holdInEscrow($subOrder);
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($subOrder->order);
        $this->assertSame(70.0, $this->ledger->getBalanceForSlug('shipping_service_revenue'));

        $subOrder->update(['is_self_delivered' => true]);
        $escrow->holdInEscrow($subOrder->fresh());
        $escrow->holdInEscrow($subOrder->fresh());

        $this->assertSame(970.0, (float) $this->wallet()->escrow_held_balance);
        $this->assertSame(970.0, $this->ledger->getBalanceForSlug('seller_escrow_payable', sellerId: $this->seller->id));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('shipping_service_revenue'));

        $escrow->releaseFromEscrow($subOrder->fresh());

        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('seller_escrow_payable', sellerId: $this->seller->id));
        $this->assertSame(970.0, $this->ledger->getBalanceForSlug('seller_wallet_payable', sellerId: $this->seller->id));
    }
}
