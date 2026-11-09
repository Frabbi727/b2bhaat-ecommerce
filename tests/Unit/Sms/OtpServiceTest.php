<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Models\Otp;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use App\Services\Sms\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MockDriver $mockDriver;

    protected OtpService $otpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $this->mockDriver);
        $this->otpService = new OtpService($this->mockDriver);
    }

    public function test_generates_and_hashes_otp_securely(): void
    {
        $result = $this->otpService->sendOtp('01712345678', 'registration');

        $this->assertTrue($result['success']);
        $code = $result['code'];
        $this->assertSame(6, strlen($code));

        $this->assertDatabaseHas('otps', [
            'phone' => '8801712345678',
            'purpose' => 'registration',
            'attempts' => 0,
        ]);

        $otpRecord = Otp::where('phone', '8801712345678')->first();
        // Plain code must NEVER be stored
        $this->assertNotEquals($code, $otpRecord->code_hash);
        $this->assertSame(hash('sha256', $code), $otpRecord->code_hash);
    }

    public function test_enforces_resend_cooldown(): void
    {
        $first = $this->otpService->sendOtp('01712345678', 'login');
        $this->assertTrue($first['success']);

        // Second request immediately should be blocked by cooldown
        $second = $this->otpService->sendOtp('01712345678', 'login');
        $this->assertFalse($second['success']);
        $this->assertSame('COOLDOWN_ACTIVE', $second['error']);
    }

    public function test_verifies_valid_otp_and_prevents_reuse(): void
    {
        $result = $this->otpService->sendOtp('01712345678', 'verification');
        $code = $result['code'];

        $verified = $this->otpService->verify('01712345678', $code, 'verification');
        $this->assertTrue($verified['success']);

        // Reusing same OTP must fail
        $reused = $this->otpService->verify('01712345678', $code, 'verification');
        $this->assertFalse($reused['success']);
        $this->assertSame('NOT_FOUND', $reused['error']);
    }

    public function test_locks_out_after_max_failed_attempts(): void
    {
        $this->otpService->sendOtp('01712345678', 'login');

        // Fail 5 times
        for ($i = 0; $i < 5; $i++) {
            $res = $this->otpService->verify('01712345678', '000000', 'login');
            $this->assertFalse($res['success']);
        }

        // 6th attempt is refused; since SEC-6 the whole phone is paused (not just this code), so it reports LOCKED
        $res = $this->otpService->verify('01712345678', '000000', 'login');
        $this->assertFalse($res['success']);
        $this->assertSame('LOCKED', $res['error']);
        $this->assertSame(5, Otp::where('phone', '8801712345678')->value('attempts'));
    }

    public function test_rejects_expired_otp(): void
    {
        $result = $this->otpService->sendOtp('01712345678', 'login');
        $code = $result['code'];

        $otpRecord = Otp::where('phone', '8801712345678')->first();
        $otpRecord->update(['expires_at' => now()->subMinute()]);

        $res = $this->otpService->verify('01712345678', $code, 'login');
        $this->assertFalse($res['success']);
        $this->assertSame('EXPIRED', $res['error']);
    }

    public function test_verifies_successfully_with_default_testing_otp(): void
    {
        config(['sms.default_otp' => '123456']);

        // In testing, the default OTP verifies a pending OTP (it never works without one)
        $this->otpService->sendOtp('01712345678', 'registration');
        $res = $this->otpService->verify('01712345678', '123456', 'registration');
        $this->assertTrue($res['success']);
    }
}
