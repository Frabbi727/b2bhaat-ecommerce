<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\ReturnRequest;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

class EscrowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    private function escrow(): EscrowLedgerService
    {
        return app(EscrowLedgerService::class);
    }

    private function wallet(?User $seller = null): SellerWallet
    {
        return SellerWallet::firstOrCreate(
            ['seller_id' => ($seller ?? $this->seller)->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        )->fresh();
    }

    private function makeSubOrder(string $paymentSlug, string $methodSlug, string $statusSlug = 'shipped'): SubOrder
    {
        $number = 'ORD-ESC-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $paymentSlug)->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $methodSlug)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'total_amount' => 1000.00,
        ]);

        return SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', $statusSlug)->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'TRK-'.uniqid(),
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
        ]);
    }

    private function deliveredHeldSubOrder(int $hoursAgo): SubOrder
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway', 'delivered');
        $this->escrow()->holdInEscrow($sub);
        OrderTrackingLog::create([
            'sub_order_id' => $sub->id,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id'),
            'title' => 'Delivered', 'description' => 'test', 'hub_location' => 'x',
            'logged_at' => now()->subHours($hoursAgo),
        ]);

        return $sub;
    }

    private function assertWalletMatchesLedger(SellerWallet $wallet): void
    {
        $sum = fn (string $type): float => (float) WalletTransaction::where('seller_wallet_id', $wallet->id)->where('transaction_type', $type)->sum('amount');
        $wallet->refresh();

        $held = $sum(WalletTransaction::TYPE_ESCROW_HOLD) + $sum(WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT)
            - $sum(WalletTransaction::TYPE_ESCROW_RELEASE) - $sum(WalletTransaction::TYPE_ESCROW_REFUND);
        $available = $sum(WalletTransaction::TYPE_ESCROW_RELEASE) - $sum(WalletTransaction::TYPE_PAYOUT)
            - $sum(WalletTransaction::TYPE_RETURN_REVERSAL);

        $this->assertEqualsWithDelta($held, (float) $wallet->escrow_held_balance, 0.001, 'Escrow balance differs from ledger');
        $this->assertEqualsWithDelta($available, (float) $wallet->available_balance, 0.001, 'Available balance differs from ledger');
    }

    public function test_prepaid_delivery_keeps_funds_in_escrow_until_holding_period(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        $this->escrow()->holdInEscrow($sub);
        $availableBefore = (float) $this->wallet()->available_balance;

        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $this->assertEqualsWithDelta($availableBefore, (float) $this->wallet()->available_balance, 0.001, 'Delivery credited available balance immediately');
    }

    public function test_cod_delivery_holds_its_own_amount_and_does_not_consume_other_orders_escrow(): void
    {
        $heldBefore = (float) $this->wallet()->escrow_held_balance;
        $sub = $this->makeSubOrder('unpaid', 'cod');

        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $this->assertEqualsWithDelta($heldBefore + 900.00, (float) $this->wallet()->escrow_held_balance, 0.001);
    }

    public function test_hold_and_release_are_idempotent(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway', 'delivered');
        $start = $this->wallet();

        $this->escrow()->holdInEscrow($sub);
        $this->escrow()->holdInEscrow($sub);
        $this->assertEqualsWithDelta((float) $start->escrow_held_balance + 900, (float) $this->wallet()->escrow_held_balance, 0.001);

        $this->escrow()->releaseFromEscrow($sub);
        $this->escrow()->releaseFromEscrow($sub);
        $this->assertEqualsWithDelta((float) $start->available_balance + 900, (float) $this->wallet()->available_balance, 0.001);
        $this->assertEqualsWithDelta((float) $start->escrow_held_balance, (float) $this->wallet()->escrow_held_balance, 0.001);
    }

    public function test_return_reversal_after_release_does_not_take_other_orders_escrow(): void
    {
        $sub = $this->deliveredHeldSubOrder(49);
        $this->escrow()->releaseFromEscrow($sub);
        $this->wallet()->update(['available_balance' => 0.00]);
        $heldBefore = (float) $this->wallet()->escrow_held_balance;

        $returnRequest = ReturnRequest::create([
            'sub_order_id' => $sub->id, 'customer_id' => $this->customer->id, 'reason' => 'damaged',
            'requested_action' => 'refund', 'description' => 'test', 'status' => ReturnRequest::STATUS_PENDING,
        ]);
        app(OrderDisputeService::class)->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_APPROVED, 'ok');

        $wallet = $this->wallet();
        $this->assertEqualsWithDelta($heldBefore, (float) $wallet->escrow_held_balance, 0.001, "Return refund took other orders' escrow");
        $this->assertEqualsWithDelta(-900.00, (float) $wallet->available_balance, 0.001, 'Reversal should leave the seller owing the released amount');
    }

    public function test_release_command_skips_sub_orders_with_open_return_request(): void
    {
        $sub = $this->deliveredHeldSubOrder(49);
        ReturnRequest::create([
            'sub_order_id' => $sub->id, 'customer_id' => $this->customer->id, 'reason' => 'damaged',
            'requested_action' => 'refund', 'description' => 'test', 'status' => ReturnRequest::STATUS_PENDING,
        ]);

        $this->artisan('escrow:release-delivered')->assertSuccessful();

        $this->assertFalse(WalletTransaction::where('sub_order_id', $sub->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists());
    }

    public function test_release_command_waits_48_hours_then_releases_exactly_once(): void
    {
        $sub = $this->deliveredHeldSubOrder(47);
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse(WalletTransaction::where('sub_order_id', $sub->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists());

        $this->travel(2)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->artisan('escrow:release-delivered')->assertSuccessful();

        $releases = WalletTransaction::where('sub_order_id', $sub->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->get();
        $this->assertCount(1, $releases);
        $this->assertEqualsWithDelta(900.00, (float) $releases->first()->amount, 0.001);
    }

    public function test_wallet_balances_equal_ledger_after_full_cycle(): void
    {
        SellerWallet::where('seller_id', $this->seller->id)->update(['available_balance' => 0, 'escrow_held_balance' => 0]);
        $this->deliveredHeldSubOrder(49);
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->escrow()->requestPayout($this->seller, 500.00, 'test');

        $this->assertWalletMatchesLedger($this->wallet());
    }

    public function test_self_delivered_hold_includes_delivery_fee(): void
    {
        $sub = $this->makeSubOrder('unpaid', 'cod');
        $sub->update(['is_self_delivered' => true, 'shipping_amount' => 70.00]);

        $this->assertEqualsWithDelta(970.00, $sub->fresh()->sellerPayableAmount(), 0.001);

        $hold = $this->escrow()->holdInEscrow($sub->fresh());
        $this->assertEqualsWithDelta(970.00, (float) $hold->amount, 0.001);
    }

    public function test_prepaid_hold_is_topped_up_when_seller_self_delivers(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        $this->escrow()->holdInEscrow($sub);
        $sub->update(['is_self_delivered' => true, 'shipping_amount' => 70.00]);

        $this->escrow()->holdInEscrow($sub->fresh());
        $this->escrow()->holdInEscrow($sub->fresh());

        $this->assertEqualsWithDelta(970.00, $this->escrow()->heldAmountFor($sub), 0.001);
        $release = $this->escrow()->releaseFromEscrow($sub->fresh());
        $this->assertEqualsWithDelta(970.00, (float) $release->amount, 0.001);
    }

    public function test_release_without_hold_releases_nothing(): void
    {
        $heldBefore = (float) $this->wallet()->escrow_held_balance;
        $sub = $this->makeSubOrder('unpaid', 'cod', 'delivered');

        $this->assertNull($this->escrow()->releaseFromEscrow($sub));
        $this->assertEqualsWithDelta($heldBefore, (float) $this->wallet()->escrow_held_balance, 0.001);
    }

    public function test_release_command_continues_when_a_sub_order_has_no_hold(): void
    {
        $unheld = $this->makeSubOrder('unpaid', 'cod', 'delivered');
        OrderTrackingLog::create([
            'sub_order_id' => $unheld->id, 'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id'),
            'title' => 'Delivered', 'description' => 'test', 'hub_location' => 'x', 'logged_at' => now()->subHours(50),
        ]);
        $held = $this->deliveredHeldSubOrder(49);

        $this->artisan('escrow:release-delivered')->assertSuccessful();

        $this->assertTrue(WalletTransaction::where('sub_order_id', $held->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists());
    }

    public function test_negative_balance_blocks_payout(): void
    {
        $this->wallet()->update(['available_balance' => -200.00]);

        $this->expectException(Exception::class);
        $this->escrow()->requestPayout($this->seller, 500.00);
    }

    public function test_wallet_transactions_unique_per_sub_order_and_type(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        $hold = $this->escrow()->holdInEscrow($sub);

        $this->expectException(UniqueConstraintViolationException::class);
        WalletTransaction::create([
            'seller_wallet_id' => $hold->seller_wallet_id,
            'sub_order_id' => $sub->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_HOLD,
            'amount' => 900.00,
            'running_balance' => 0,
            'reference_note' => 'duplicate',
        ]);
    }

    public function test_mixed_cod_order_holds_and_releases_per_sub_order(): void
    {
        $order = MixedOrderFixture::create();
        [$subA, $subB] = [$order->subOrders->firstWhere('is_self_delivered', true), $order->subOrders->firstWhere('is_self_delivered', false)];
        $walletA = $this->wallet($subA->seller);
        $walletB = $this->wallet($subB->seller);
        $startA = (float) $walletA->available_balance;
        $startB = (float) $walletB->available_balance;

        app(CourierStatusService::class)->updateStatus($subA, 'delivered');
        app(CourierStatusService::class)->updateStatus($subB, 'delivered');
        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();

        // A self-delivered COD: seller kept ৳1,036.67 cash, so they are charged commission − coupon share = ৳66.67 (C2)
        $this->assertEqualsWithDelta($startA - 66.67, (float) $walletA->fresh()->available_balance, 0.001);
        $this->assertEqualsWithDelta($startB + 450.00, (float) $walletB->fresh()->available_balance, 0.001);
        $this->assertWalletMatchesLedger($walletB->fresh());
    }

    public function test_courier_returned_after_release_reverses_seller_credit(): void
    {
        $sub = $this->deliveredHeldSubOrder(49);
        $this->escrow()->releaseFromEscrow($sub);
        $availableAfterRelease = (float) $this->wallet()->available_balance;

        app(CourierStatusService::class)->updateStatus($sub->fresh(), 'returned');

        $this->assertEqualsWithDelta($availableAfterRelease - 900.00, (float) $this->wallet()->available_balance, 0.001);
        $this->assertTrue(WalletTransaction::where('sub_order_id', $sub->id)->where('transaction_type', WalletTransaction::TYPE_RETURN_REVERSAL)->exists());
    }
}
