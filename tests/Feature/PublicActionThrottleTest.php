<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\OrderTracking;
use App\Models\Order;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use App\Services\Sms\OtpService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-6: the public order lookup and the SMS code check can't be tried without limit.
 */
class PublicActionThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->app->instance(SmsGatewayInterface::class, new MockDriver);
    }

    public function test_order_lookup_pauses_after_ten_tries_from_one_address(): void
    {
        $order = Order::firstOrFail();
        $phone = (string) $order->shipping_address['recipient_phone'];

        for ($i = 0; $i < 10; $i++) {
            Livewire::test(OrderTracking::class)
                ->set('orderNumber', 'ORD-GUESS-'.$i)
                ->set('phone', '01700000000')
                ->call('searchOrder')
                ->assertSet('order', null);
        }

        // Even the right details are refused until the pause ends.
        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $order->order_number)
            ->set('phone', $phone)
            ->call('searchOrder')
            ->assertSet('order', null)
            ->assertSet('searchError', fn (string $error): bool => str_contains($error, 'Too many lookups'));
    }

    public function test_order_lookup_works_normally_under_the_limit(): void
    {
        $order = Order::firstOrFail();

        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $order->order_number)
            ->set('phone', (string) $order->shipping_address['recipient_phone'])
            ->call('searchOrder')
            ->assertSet('searchError', '')
            ->assertSet('verifiedAccess', true);
    }

    /**
     * Wave 1 review M-8: refreshing a found order (its details are in the address bar) is not guessing, so it never
     * uses up the lookup limit.
     */
    public function test_refreshing_a_found_order_does_not_use_up_lookups(): void
    {
        $order = Order::firstOrFail();
        $params = ['order' => $order->order_number, 'phone' => (string) $order->shipping_address['recipient_phone']];

        for ($i = 0; $i < 12; $i++) {
            Livewire::withQueryParams($params)
                ->test(OrderTracking::class)
                ->assertSet('searchError', '')
                ->assertSet('verifiedAccess', true);
        }

        $this->assertSame(0, RateLimiter::attempts('order-lookup:127.0.0.1'));
    }

    public function test_five_wrong_codes_lock_the_phone_even_with_a_new_code(): void
    {
        $otp = app(OtpService::class);
        $otp->sendOtp('01712345678', 'login');

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('INVALID_CODE', $otp->verify('01712345678', '00000'.$i, 'login')['error']);
        }

        // A fresh code (after the resend cooldown) must not reset the count.
        $this->travel(2)->minutes();
        $fresh = $otp->sendOtp('01712345678', 'login');
        $this->assertTrue($fresh['success']);

        $result = $otp->verify('01712345678', $fresh['code'], 'login');
        $this->assertFalse($result['success']);
        $this->assertSame('LOCKED', $result['error']);
        $this->assertGreaterThan(0, $result['seconds_remaining']);

        // After the pause the right code works.
        $this->travel(16)->minutes();
        $later = $otp->sendOtp('01712345678', 'login');
        $this->assertTrue($otp->verify('01712345678', $later['code'], 'login')['success']);
    }

    public function test_a_right_code_clears_earlier_mistakes(): void
    {
        $otp = app(OtpService::class);
        $sent = $otp->sendOtp('01712345678', 'login');

        $otp->verify('01712345678', '000000', 'login');
        $otp->verify('01712345678', '000001', 'login');
        $this->assertTrue($otp->verify('01712345678', $sent['code'], 'login')['success']);

        $this->assertSame(0, RateLimiter::attempts('otp-verify:login:8801712345678'));
    }

    public function test_attempts_left_counts_down_from_the_limit(): void
    {
        $otp = app(OtpService::class);
        $otp->sendOtp('01712345678', 'login');

        $this->assertSame(4, $otp->verify('01712345678', '000000', 'login')['attempts_left']);
        $this->assertSame(3, $otp->verify('01712345678', '000001', 'login')['attempts_left']);
    }
}
