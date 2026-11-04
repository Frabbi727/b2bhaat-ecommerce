<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use App\Services\Sms\OrderNotificationService;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected MockDriver $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SmsTemplateSeeder::class);

        $this->mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $this->mockDriver);

        Role::create(['name' => 'Retail Buyer', 'slug' => 'retail_buyer']);
        OrderStatus::create(['name' => 'Confirmed', 'slug' => 'confirmed']);
        PaymentStatus::create(['name' => 'Paid', 'slug' => 'paid']);
        PaymentMethod::create(['name' => 'Cash on Delivery', 'slug' => 'cod']);
    }

    public function test_dispatches_order_confirmation_sms_with_invoice_and_tracking_tokens(): void
    {
        $customer = User::create([
            'role_id' => 1,
            'name' => 'Tanvir Khan',
            'email' => 'tanvir@example.com',
            'phone' => '8801712345678',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'order_number' => 'ORD-10025',
            'customer_id' => $customer->id,
            'total_amount' => 2500,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Tanvir Khan',
                'recipient_phone' => '01712345678',
            ],
        ]);

        $service = app(OrderNotificationService::class);
        $result = $service->sendOrderConfirmation($order);

        $this->assertTrue($result);
        $this->assertTrue($this->mockDriver->hasSentTo('8801712345678'));
        $this->assertNotNull($order->fresh()->sms_confirmation_sent_at);

        // Verify tokens are included in the message
        $lastSent = end($this->mockDriver->sentMessages);
        $this->assertStringContainsString($order->invoice_token, $lastSent['message']);

        // Verify duplicate dispatch prevention
        $this->mockDriver->reset();
        $secondResult = $service->sendOrderConfirmation($order);
        $this->assertFalse($secondResult);
        $this->assertFalse($this->mockDriver->hasSentTo('8801712345678'));
    }

    public function test_dispatches_order_status_update_sms(): void
    {
        $customer = User::create([
            'role_id' => 1,
            'name' => 'Status Update User',
            'email' => 'status@example.com',
            'phone' => '8801711223344',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'order_number' => 'ORD-55555',
            'customer_id' => $customer->id,
            'total_amount' => 3000,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Status Update User',
                'recipient_phone' => '01711223344',
            ],
        ]);

        $service = app(OrderNotificationService::class);
        $result = $service->sendOrderStatusUpdate($order, 'Shipped', 'Steadfast Courier', 'STF-9988');

        $this->assertTrue($result);
        $this->assertTrue($this->mockDriver->hasSentTo('8801711223344'));

        $lastSent = end($this->mockDriver->sentMessages);
        $this->assertStringContainsString('Shipped', $lastSent['message']);
        $this->assertStringContainsString($order->tracking_token, $lastSent['message']);
    }

    public function test_dispatches_resend_invoice_sms(): void
    {
        $customer = User::create([
            'role_id' => 1,
            'name' => 'Invoice Resend User',
            'email' => 'invoice@example.com',
            'phone' => '8801755667788',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'order_number' => 'ORD-77777',
            'customer_id' => $customer->id,
            'total_amount' => 1500,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Invoice Resend User',
                'recipient_phone' => '01755667788',
            ],
        ]);

        $service = app(OrderNotificationService::class);
        $result = $service->sendInvoiceLink($order);

        $this->assertTrue($result);
        $this->assertTrue($this->mockDriver->hasSentTo('8801755667788'));

        $lastSent = end($this->mockDriver->sentMessages);
        $this->assertStringContainsString($order->invoice_token, $lastSent['message']);
    }

    public function test_can_view_invoice_via_secure_token_route(): void
    {
        $customer = User::create([
            'role_id' => 1,
            'name' => 'Customer Token Test',
            'email' => 'tokentest@example.com',
            'phone' => '8801799999999',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'order_number' => 'ORD-99999',
            'customer_id' => $customer->id,
            'total_amount' => 1000,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Customer Token Test',
                'recipient_phone' => '01799999999',
            ],
        ]);

        $response = $this->get(route('order.invoice.token', ['token' => $order->invoice_token]));
        $response->assertOk();
        $response->assertSee('ORD-99999');
    }

    public function test_can_view_order_tracking_via_secure_token_route(): void
    {
        $customer = User::create([
            'role_id' => 1,
            'name' => 'Track Token Test',
            'email' => 'tracktest@example.com',
            'phone' => '8801788888888',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'order_number' => 'ORD-88888',
            'customer_id' => $customer->id,
            'total_amount' => 1200,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Track Token Test',
                'recipient_phone' => '01788888888',
            ],
        ]);

        $response = $this->get(route('order.track.token', ['token' => $order->tracking_token]));
        $response->assertOk();
    }
}
