<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Livewire\Storefront\CustomerAuth;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use App\Services\Sms\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerAuthOtpTest extends TestCase
{
    use RefreshDatabase;

    protected MockDriver $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Retail Buyer', 'slug' => 'retail_buyer']);
        Role::create(['name' => 'Retail', 'slug' => 'retail']);

        $this->mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $this->mockDriver);
    }

    public function test_customer_registration_requires_otp_before_creating_user(): void
    {
        $component = Livewire::test(CustomerAuth::class)
            ->set('mode', 'register')
            ->set('registerName', 'Rahim Ahmed')
            ->set('registerPhone', '01712345678')
            ->set('registerEmail', 'rahim@example.com')
            ->set('registerPassword', 'Password123!')
            ->set('registerPasswordConfirmation', 'Password123!')
            ->set('agreeTerms', true)
            ->call('register');

        $component->assertSet('step', 'otp_verification');
        $component->assertSee('Enter 6-digit OTP');

        // Verify user was NOT created yet
        $this->assertDatabaseMissing('users', ['email' => 'rahim@example.com']);

        // Fetch generated OTP from database
        $otp = Otp::where('phone', '8801712345678')->first();
        $this->assertNotNull($otp);

        // Attempt with wrong code
        $component->set('otpCode', '000000')
            ->call('completeRegistration')
            ->assertSee('Invalid OTP');

        $this->assertDatabaseMissing('users', ['email' => 'rahim@example.com']);
    }

    public function test_successful_otp_completes_registration_and_logs_in(): void
    {
        Livewire::test(CustomerAuth::class)
            ->set('mode', 'register')
            ->set('registerName', 'Rahim Ahmed')
            ->set('registerPhone', '01712345678')
            ->set('registerEmail', 'rahim@example.com')
            ->set('registerPassword', 'Password123!')
            ->set('registerPasswordConfirmation', 'Password123!')
            ->set('agreeTerms', true)
            ->call('register');

        // Extract sent code from mock driver message
        $lastSent = end($this->mockDriver->sentMessages);
        preg_match('/\b(\d{6})\b/', $lastSent['message'], $matches);
        $code = $matches[1];

        Livewire::test(CustomerAuth::class)
            ->set('mode', 'register')
            ->set('step', 'otp_verification')
            ->set('registerName', 'Rahim Ahmed')
            ->set('registerPhone', '01712345678')
            ->set('registerEmail', 'rahim@example.com')
            ->set('registerPassword', 'Password123!')
            ->set('registerPasswordConfirmation', 'Password123!')
            ->set('otpCode', $code)
            ->call('completeRegistration')
            ->assertRedirect(route('customer.orders'));

        $user = User::where('email', 'rahim@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('8801712345678', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(Auth::check());
    }

    public function test_login_with_otp_flow(): void
    {
        $role = Role::where('slug', 'retail')->first();
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Existing Customer',
            'phone' => '8801812345678',
            'email' => 'existing@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        $component = Livewire::test(CustomerAuth::class)
            ->set('mode', 'login')
            ->set('loginMethod', 'otp')
            ->set('otpPhone', '01812345678')
            ->call('sendLoginOtp');

        $component->assertSet('loginStep', 'otp');

        // Extract sent code
        $lastSent = end($this->mockDriver->sentMessages);
        preg_match('/\b(\d{6})\b/', $lastSent['message'], $matches);
        $code = $matches[1];

        $component->set('otpCode', $code)
            ->call('verifyLoginOtp')
            ->assertRedirect(route('customer.orders'));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    /**
     * SEC-7: panel accounts (super admin, seller) must sign in with their password, never with an SMS code alone.
     */
    public function test_admin_phone_gets_no_login_code_and_cannot_sign_in_by_otp(): void
    {
        $adminRole = Role::create(['name' => 'Super Administrator', 'slug' => 'super_admin']);
        User::create([
            'role_id' => $adminRole->id,
            'name' => 'Platform Admin',
            'phone' => '8801912345678',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $component = Livewire::test(CustomerAuth::class)
            ->set('mode', 'login')
            ->set('loginMethod', 'otp')
            ->set('otpPhone', '01912345678')
            ->call('sendLoginOtp');

        $this->assertSame(0, Otp::where('phone', '8801912345678')->count());
        $this->assertEmpty($this->mockDriver->sentMessages);

        Otp::create([
            'phone' => '8801912345678',
            'code_hash' => hash('sha256', '123456'),
            'purpose' => 'login',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
        ]);

        $component->set('loginStep', 'otp')
            ->set('otpCode', '123456')
            ->call('verifyLoginOtp');

        $this->assertFalse(Auth::check());
    }

    public function test_unknown_and_known_phones_get_the_same_reply(): void
    {
        $role = Role::where('slug', 'retail')->first();
        User::create([
            'role_id' => $role->id,
            'name' => 'Existing Customer',
            'phone' => '8801812345678',
            'email' => 'existing@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $known = Livewire::test(CustomerAuth::class)
            ->set('mode', 'login')->set('loginMethod', 'otp')
            ->set('otpPhone', '01812345678')->call('sendLoginOtp');
        $unknown = Livewire::test(CustomerAuth::class)
            ->set('mode', 'login')->set('loginMethod', 'otp')
            ->set('otpPhone', '01512345678')->call('sendLoginOtp');

        $this->assertSame(
            str_replace('01812345678', 'PHONE', $known->get('successMessage')),
            str_replace('01512345678', 'PHONE', $unknown->get('successMessage'))
        );
        $this->assertSame($known->get('errorMessage'), $unknown->get('errorMessage'));
        $this->assertSame('otp', $unknown->get('loginStep'));
    }

    public function test_one_network_address_cannot_request_codes_for_many_phones(): void
    {
        config(['sms.otp.max_per_ip_per_hour' => 3]);
        $service = app(OtpService::class);

        foreach (['01811111111', '01822222222', '01833333333'] as $phone) {
            $this->assertTrue($service->sendOtp($phone, 'login')['success']);
        }

        $blocked = $service->sendOtp('01844444444', 'login');

        $this->assertFalse($blocked['success']);
        $this->assertSame('RATE_LIMITED', $blocked['error']);
    }
}
