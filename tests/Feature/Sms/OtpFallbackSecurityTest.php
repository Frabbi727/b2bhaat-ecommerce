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

class OtpFallbackSecurityTest extends TestCase
{
    use RefreshDatabase;

    private MockDriver $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Retail Buyer', 'slug' => 'retail_buyer']);
        $this->mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $this->mockDriver);
        config(['sms.default_otp' => '123456']);
    }

    private function makeUser(string $phone): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'role_id' => Role::where('slug', 'retail_buyer')->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_default_otp_cannot_log_in_in_production(): void
    {
        $victim = $this->makeUser('8801799887766');
        $this->app->detectEnvironment(fn (): string => 'production');

        Livewire::test(CustomerAuth::class)
            ->set('otpPhone', '01799887766')
            ->call('sendLoginOtp')
            ->set('otpCode', '123456')
            ->call('verifyLoginOtp');

        $this->assertFalse(Auth::check(), 'Fallback OTP authenticated user '.$victim->id.' in production');
    }

    public function test_default_otp_still_works_in_testing_when_otp_pending(): void
    {
        $otpService = app(OtpService::class);
        $otpService->sendOtp('01712345678', 'login');

        $this->assertTrue($otpService->verify('01712345678', '123456', 'login')['success']);
    }

    public function test_default_otp_fails_when_no_otp_was_requested_even_in_testing(): void
    {
        $result = app(OtpService::class)->verify('01712345678', '123456', 'login');

        $this->assertFalse($result['success']);
    }

    public function test_user_stored_with_plus_880_phone_logs_in_with_real_otp(): void
    {
        $user = $this->makeUser('+8801799887766');

        $component = Livewire::test(CustomerAuth::class)
            ->set('otpPhone', '01799887766')
            ->call('sendLoginOtp')
            ->assertSet('loginStep', 'otp');

        $this->assertNotNull(Otp::where('phone', '8801799887766')->first());

        $lastMessage = end($this->mockDriver->sentMessages)['message'] ?? '';
        $this->assertSame(1, preg_match('/\b(\d{6})\b/', $lastMessage, $matches), 'No OTP SMS captured');
        $code = $matches[1];

        $component->set('otpCode', $code)->call('verifyLoginOtp');

        $this->assertSame($user->id, Auth::id());
    }

    public function test_user_stored_with_plus_880_phone_logs_in_with_password(): void
    {
        $user = $this->makeUser('+8801799887766');
        $user->update(['password' => bcrypt('Secret-pass-123')]);

        Livewire::test(CustomerAuth::class)
            ->set('loginIdentifier', '01799887766')
            ->set('loginPassword', 'Secret-pass-123')
            ->call('login');

        $this->assertSame($user->id, Auth::id());
    }
}
