<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SendSmsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_sms_job_dispatches_driver_and_writes_sms_log(): void
    {
        $mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $mockDriver);

        $job = new SendSmsJob(
            phone: '01712345678',
            message: 'Your verification OTP is 123456.',
            purpose: 'otp_verification'
        );

        $job->handle($mockDriver);

        $this->assertDatabaseHas('sms_logs', [
            'recipient_phone' => '8801712345678',
            'purpose' => 'otp_verification',
            'status' => 'sent',
        ]);

        // Verify OTP is masked in database log
        $log = SmsLog::where('recipient_phone', '8801712345678')->first();
        $this->assertStringContainsString('******', $log->message);
        $this->assertStringNotContainsString('123456', $log->message);

        $this->assertTrue($mockDriver->hasSentTo('8801712345678'));
    }

    public function test_prevents_duplicate_sms_within_cooldown_window(): void
    {
        Cache::flush();
        $mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $mockDriver);

        $job1 = new SendSmsJob('01712345678', 'Identical Msg', 'test');
        $job1->handle($mockDriver);

        $job2 = new SendSmsJob('01712345678', 'Identical Msg', 'test');
        $job2->handle($mockDriver);

        // Only one log created because duplicate lock prevented second send
        $this->assertEquals(1, SmsLog::where('recipient_phone', '8801712345678')->count());
    }

    public function test_skips_dispatch_when_sms_is_globally_disabled(): void
    {
        SmsSetting::set('sms_enabled', 'false');

        $mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $mockDriver);

        $job = new SendSmsJob('01712345678', 'Hello there', 'test_disabled');
        $job->handle($mockDriver);

        $this->assertDatabaseHas('sms_logs', [
            'recipient_phone' => '8801712345678',
            'status' => 'skipped',
        ]);
        $this->assertFalse($mockDriver->hasSentTo('8801712345678'));
    }
}
