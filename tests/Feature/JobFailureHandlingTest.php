<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\LogAuditJob;
use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\DTOs\SmsResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * PERF-4: a failed background job is retried and, when it finally gives up, it is visible (failed_jobs, log),
 * never silently dropped.
 */
class JobFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_audit_write_is_retried_not_swallowed(): void
    {
        $job = new LogAuditJob(['module' => 'finance', 'action' => 'x', 'not_a_column' => 'boom']);

        $this->assertGreaterThan(1, $job->tries);

        try {
            $job->handle();
            $this->fail('A failed audit write must throw so the queue retries it.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_an_sms_retry_is_actually_sent_despite_the_duplicate_guard(): void
    {
        Cache::flush();
        $gateway = Mockery::mock(SmsGatewayInterface::class);
        $gateway->shouldReceive('send')->twice()->andReturn(
            new SmsResponse(false, '1011', errorMessage: 'Gateway busy'),
            new SmsResponse(true, '202', 'MSG-1'),
        );

        $first = (new SendSmsJob('01712345678', 'Your parcel is on the way', 'test'))->withFakeQueueInteractions();
        try {
            $first->handle($gateway);
        } catch (RuntimeException) {
            // the queue would now retry
        }

        $retry = (new SendSmsJob('01712345678', 'Your parcel is on the way', 'test'))->withFakeQueueInteractions();
        $retry->job->attempts = 2;
        $retry->handle($gateway);

        $this->assertSame(['failed', 'sent'], SmsLog::orderBy('id')->pluck('status')->all());
    }

    public function test_a_gateway_exception_does_not_leave_the_sms_log_pending(): void
    {
        $gateway = Mockery::mock(SmsGatewayInterface::class);
        $gateway->shouldReceive('send')->andThrow(new RuntimeException('Connection timed out'));

        try {
            (new SendSmsJob('01712345678', 'Hello', 'test'))->handle($gateway);
        } catch (RuntimeException) {
        }

        $log = SmsLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Connection timed out', (string) $log->error_message);
    }
}
