<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Finance\PayoutStatusUpdatedEvent;
use App\Events\Finance\RefundSettledEvent;
use App\Mail\Customer\RefundSettledMail;
use App\Mail\Seller\PayoutStatusMail;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Refund;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FinanceEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receives_email_when_refund_is_settled(): void
    {
        Mail::fake();

        $customer = User::factory()->create(['email' => 'refundbuyer@retail.com', 'name' => 'Refund Buyer']);
        $order = $this->createMockOrder($customer);

        $refund = Refund::create([
            'order_id' => $order->id,
            'sub_order_id' => null,
            'amount' => 450.00,
            'reason' => 'Item damaged during courier transit',
            'status' => Refund::STATUS_PAID,
            'method' => 'bkash',
            'reference' => 'BKASH-REFUND-9988',
            'paid_at' => now(),
        ]);

        event(new RefundSettledEvent($refund));

        Mail::assertQueued(RefundSettledMail::class, function (RefundSettledMail $mail) {
            return $mail->hasTo('refundbuyer@retail.com');
        });
    }

    public function test_seller_receives_email_when_payout_is_disbursed(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['email' => 'sellerpay@factory.com', 'name' => 'Seller Pay']);
        $wallet = SellerWallet::create([
            'seller_id' => $seller->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 0.00,
        ]);

        $payout = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'amount' => 2000.00,
            'running_balance' => 3000.00,
            'reference_note' => 'Disbursed via Bank Trx #BANK9922',
        ]);

        event(new PayoutStatusUpdatedEvent($payout, 'disbursed', 'BANK9922'));

        Mail::assertQueued(PayoutStatusMail::class, function (PayoutStatusMail $mail) {
            return $mail->hasTo('sellerpay@factory.com');
        });
    }

    private function createMockOrder(User $customer): Order
    {
        $status = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);

        return Order::create([
            'order_number' => 'ORD-'.date('Ymd').'-REF99',
            'customer_id' => $customer->id,
            'order_status_id' => $status->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'total_amount' => 1000.00,
            'shipping_amount' => 60.00,
            'discount_amount' => 0.00,
            'shipping_address' => [
                'recipient_name' => $customer->name,
                'recipient_phone' => '01711111111',
                'street_address' => 'Mirpur-10',
                'city' => 'Dhaka',
            ],
            'invoice_token' => 'ref-inv-token',
            'tracking_token' => 'ref-trk-token',
        ]);
    }
}
