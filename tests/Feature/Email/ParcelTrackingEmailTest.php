<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Orders\ConsignmentStatusUpdatedEvent;
use App\Mail\Customer\ParcelStatusUpdateMail;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ParcelTrackingEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_email_when_consignment_status_is_shipped_or_delivered(): void
    {
        Mail::fake();

        $customer = User::factory()->create(['email' => 'buyer@b2bhaat.com', 'name' => 'Buyer One']);
        $seller = User::factory()->create(['email' => 'seller@factory.com']);
        $order = $this->createMockOrder($customer);

        $shipped = FulfillmentStatus::firstOrCreate(['slug' => 'shipped'], ['name' => 'Shipped']);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => $order->order_number.'-A',
            'fulfillment_status_id' => $shipped->id,
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'STDF12345678',
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 60.00,
        ]);

        event(new ConsignmentStatusUpdatedEvent($subOrder, 'shipped'));

        Mail::assertQueued(ParcelStatusUpdateMail::class, function (ParcelStatusUpdateMail $mail) {
            return $mail->hasTo('buyer@b2bhaat.com');
        });
    }

    public function test_does_not_send_email_for_internal_processing_steps(): void
    {
        Mail::fake();

        $customer = User::factory()->create(['email' => 'buyer@b2bhaat.com']);
        $seller = User::factory()->create();
        $order = $this->createMockOrder($customer);
        $processing = FulfillmentStatus::firstOrCreate(['slug' => 'processing'], ['name' => 'Processing']);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => $order->order_number.'-A',
            'fulfillment_status_id' => $processing->id,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 60.00,
        ]);

        event(new ConsignmentStatusUpdatedEvent($subOrder, 'processing'));

        Mail::assertNothingQueued();
    }

    private function createMockOrder(User $customer): Order
    {
        $status = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash on Delivery']);

        return Order::create([
            'order_number' => 'ORD-'.date('Ymd').'-TRK99',
            'customer_id' => $customer->id,
            'order_status_id' => $status->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'total_amount' => 560.00,
            'shipping_amount' => 60.00,
            'discount_amount' => 0.00,
            'shipping_address' => [
                'recipient_name' => $customer->name,
                'recipient_phone' => '01711111111',
                'street_address' => 'Mirpur-10',
                'city' => 'Dhaka',
            ],
            'invoice_token' => 'trk-inv-token',
            'tracking_token' => 'trk-live-token',
        ]);
    }
}
