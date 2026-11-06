<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Orders\OrderPlacedEvent;
use App\Listeners\Orders\SendSellerNewConsignmentEmailListener;
use App\Mail\Seller\NewConsignmentOrderMail;
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

class SellerConsignmentEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_vendor_receives_only_their_sub_order(): void
    {
        Mail::fake();

        $sellerA = User::factory()->create(['email' => 'sellerA@factory.com', 'name' => 'Seller A']);
        $sellerB = User::factory()->create(['email' => 'sellerB@mill.com', 'name' => 'Seller B']);

        $customer = User::factory()->create(['email' => 'buyer@retail.com']);
        $order = $this->createMockOrder($customer);

        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $subOrderA = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'sub_order_number' => $order->order_number.'-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 800.00,
            'commission_amount' => 80.00,
            'shipping_amount' => 60.00,
        ]);

        $subOrderB = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerB->id,
            'sub_order_number' => $order->order_number.'-B',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 40.00,
        ]);

        $listener = app(SendSellerNewConsignmentEmailListener::class);
        $listener->handle(new OrderPlacedEvent($order));

        Mail::assertQueued(NewConsignmentOrderMail::class, fn (NewConsignmentOrderMail $m) => $m->hasTo('sellera@factory.com'));
        Mail::assertQueued(NewConsignmentOrderMail::class, fn (NewConsignmentOrderMail $m) => $m->hasTo('sellerb@mill.com'));
        $this->assertCount(2, Mail::queued(NewConsignmentOrderMail::class));
    }

    private function createMockOrder(User $customer): Order
    {
        $status = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash on Delivery']);

        return Order::create([
            'order_number' => 'ORD-'.date('Ymd').'-SLR99',
            'customer_id' => $customer->id,
            'order_status_id' => $status->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'total_amount' => 1250.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
            'shipping_address' => [
                'recipient_name' => $customer->name,
                'recipient_phone' => '01711111111',
                'street_address' => 'Mirpur-10',
                'city' => 'Dhaka',
            ],
            'invoice_token' => 'test-invoice-slr',
            'tracking_token' => 'test-tracking-slr',
        ]);
    }
}
