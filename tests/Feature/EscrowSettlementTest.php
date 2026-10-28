<?php

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected EscrowLedgerService $escrowService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->escrowService = app(EscrowLedgerService::class);
    }

    public function test_escrow_hold_and_release_flow(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $wallet = SellerWallet::where('seller_id', $seller->id)->firstOrFail();

        $initialHeld = (float) $wallet->escrow_held_balance;
        $initialAvailable = (float) $wallet->available_balance;

        $orderStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
        $paymentStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'bkash')->firstOrFail();
        $fulfillmentStatus = FulfillmentStatus::where('slug', 'shipped')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-TEST-001',
            'customer_id' => User::where('email', 'customer@retail.com')->value('id'),
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 1000.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-TEST-001-A',
            'fulfillment_status_id' => $fulfillmentStatus->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00, // net: 900.00
        ]);

        // 1. Hold in escrow upon customer payment
        $holdTx = $this->escrowService->holdInEscrow($subOrder);
        $this->assertEquals(WalletTransaction::TYPE_ESCROW_HOLD, $holdTx->transaction_type);
        $this->assertEquals(900.00, (float) $holdTx->amount);

        $wallet->refresh();
        $this->assertEquals($initialHeld + 900.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals($initialAvailable, (float) $wallet->available_balance);

        // 2. Release from escrow upon order delivery
        $releaseTx = $this->escrowService->releaseFromEscrow($subOrder);
        $this->assertEquals(WalletTransaction::TYPE_ESCROW_RELEASE, $releaseTx->transaction_type);
        $this->assertEquals(900.00, (float) $releaseTx->amount);

        $wallet->refresh();
        $this->assertEquals($initialHeld, (float) $wallet->escrow_held_balance);
        $this->assertEquals($initialAvailable + 900.00, (float) $wallet->available_balance);

        // 3. Seller requests payout
        $payoutTx = $this->escrowService->requestPayout($seller, 500.00);
        $this->assertEquals(WalletTransaction::TYPE_PAYOUT, $payoutTx->transaction_type);
        $this->assertEquals(500.00, (float) $payoutTx->amount);

        $wallet->refresh();
        $this->assertEquals($initialAvailable + 400.00, (float) $wallet->available_balance);
    }
}
