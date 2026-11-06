<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Orders\OrderPlacedEvent;
use App\Mail\Customer\OrderConfirmationMail;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_single_aggregated_email_for_registered_customer(): void
    {
        Mail::fake();

        $customer = User::factory()->create([
            'email' => 'customer@retail.com',
            'name' => 'John Customer',
        ]);

        $order = $this->createMockOrder($customer);

        event(new OrderPlacedEvent($order));

        Mail::assertQueued(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) {
            return $mail->hasTo('customer@retail.com');
        });
    }

    public function test_skips_synthetic_guest_email_without_exception(): void
    {
        Mail::fake();

        $guest = User::factory()->create([
            'email' => 'guest+uuid123@guest.b2bhaat.invalid',
            'name' => 'Guest Buyer',
        ]);

        $order = $this->createMockOrder($guest);

        event(new OrderPlacedEvent($order));

        Mail::assertNothingQueued();
    }

    private function createMockOrder(User $customer): Order
    {
        $status = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash on Delivery']);

        return Order::create([
            'order_number' => 'ORD-'.date('Ymd').'-TEST99',
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
            'invoice_token' => 'test-invoice-token-12345',
            'tracking_token' => 'test-tracking-token-12345',
        ]);
    }
}
