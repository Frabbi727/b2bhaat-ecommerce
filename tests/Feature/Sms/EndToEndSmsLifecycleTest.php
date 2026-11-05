<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Livewire\Storefront\CustomerAuth;
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
use Livewire\Livewire;
use Tests\TestCase;

class EndToEndSmsLifecycleTest extends TestCase
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
        Role::create(['name' => 'Retail', 'slug' => 'retail']);
        Role::create(['name' => 'Admin', 'slug' => 'admin']);

        OrderStatus::create(['name' => 'Confirmed', 'slug' => 'confirmed']);
        OrderStatus::create(['name' => 'Shipped', 'slug' => 'shipped']);
        PaymentStatus::create(['name' => 'Paid', 'slug' => 'paid']);
        PaymentMethod::create(['name' => 'Cash on Delivery', 'slug' => 'cod']);
    }

    public function test_complete_sms_lifecycle_from_registration_to_order_and_tracking(): void
    {
        // 1. Customer initiates registration
        Livewire::test(CustomerAuth::class)
            ->set('mode', 'register')
            ->set('registerName', 'Sultana Begum')
            ->set('registerPhone', '01719876543')
            ->set('registerEmail', 'sultana@example.com')
            ->set('registerPassword', 'SecurePassword123!')
            ->set('registerPasswordConfirmation', 'SecurePassword123!')
            ->set('agreeTerms', true)
            ->call('register')
            ->assertSet('step', 'otp_verification');

        $this->assertTrue($this->mockDriver->hasSentTo('8801719876543'));

        // Extract sent OTP code
        $lastSent = end($this->mockDriver->sentMessages);
        preg_match('/\b(\d{6})\b/', $lastSent['message'], $matches);
        $otpCode = $matches[1];

        // 2. Verify OTP to complete registration
        Livewire::test(CustomerAuth::class)
            ->set('mode', 'register')
            ->set('step', 'otp_verification')
            ->set('registerName', 'Sultana Begum')
            ->set('registerPhone', '01719876543')
            ->set('registerEmail', 'sultana@example.com')
            ->set('registerPassword', 'SecurePassword123!')
            ->set('registerPasswordConfirmation', 'SecurePassword123!')
            ->set('otpCode', $otpCode)
            ->call('completeRegistration')
            ->assertRedirect(route('customer.orders'));

        $user = User::where('email', 'sultana@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->phone_verified_at);

        // 3. Customer places an order
        $order = Order::create([
            'order_number' => 'ORD-2026-E2E',
            'customer_id' => $user->id,
            'total_amount' => 4500.00,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_address' => [
                'recipient_name' => 'Sultana Begum',
                'recipient_phone' => '01719876543',
                'street_address' => 'House 12, Road 4, Dhanmondi',
                'city' => 'Dhaka',
            ],
        ]);

        $this->assertNotNull($order->invoice_token);
        $this->assertNotNull($order->tracking_token);

        // 4. Trigger order confirmation SMS
        $notificationService = app(OrderNotificationService::class);
        $this->mockDriver->reset();
        $sentConfirmation = $notificationService->sendOrderConfirmation($order);

        $this->assertTrue($sentConfirmation);
        $this->assertTrue($this->mockDriver->hasSentTo('8801719876543'));

        // Check invoice URL and tracking URL in message
        $lastSms = end($this->mockDriver->sentMessages);
        $this->assertStringContainsString($order->invoice_token, $lastSms['message']);
        $this->assertStringContainsString($order->tracking_token, $lastSms['message']);

        // 5. Customer accesses invoice view via token URL
        $invoiceResponse = $this->get(route('order.invoice.token', ['token' => $order->invoice_token]));
        $invoiceResponse->assertOk();
        $invoiceResponse->assertSee('ORD-2026-E2E');

        // 6. Customer accesses tracking view via token URL
        $trackingResponse = $this->get(route('order.track.token', ['token' => $order->tracking_token]));
        $trackingResponse->assertOk();
        $trackingResponse->assertSee('ORD-2026-E2E');

        // 7. Order status changes to "Shipped" -> Dispatches Status Update SMS
        $this->mockDriver->reset();
        $sentStatusUpdate = $notificationService->sendOrderStatusUpdate(
            order: $order,
            newStatus: 'Shipped',
            courierName: 'Paperfly Logistics',
            trackingNumber: 'PFLY-998877'
        );

        $this->assertTrue($sentStatusUpdate);
        $this->assertTrue($this->mockDriver->hasSentTo('8801719876543'));
        $statusSms = end($this->mockDriver->sentMessages);
        $this->assertStringContainsString('Shipped', $statusSms['message']);
        $this->assertStringContainsString($order->tracking_token, $statusSms['message']);

        // 8. SMS logs verify masked OTP and recorded statuses
        $this->assertDatabaseHas('sms_logs', [
            'recipient_phone' => '8801719876543',
            'purpose' => 'otp_registration',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('sms_logs', [
            'recipient_phone' => '8801719876543',
            'purpose' => 'order_confirmation',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('sms_logs', [
            'recipient_phone' => '8801719876543',
            'purpose' => 'order_status_update',
            'status' => 'sent',
        ]);
    }
}
