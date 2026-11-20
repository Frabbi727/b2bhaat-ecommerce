<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailLog;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_synthetic_guest_invalid_domains_and_malformed_emails(): void
    {
        $resolver = app(EmailRecipientResolver::class);

        $this->assertFalse($resolver->isDeliverable('guest+550e8400-e29b-41d4-a716-446655440000@guest.b2bhaat.invalid'));
        $this->assertFalse($resolver->isDeliverable('user@example.invalid'));
        $this->assertFalse($resolver->isDeliverable('not-an-email'));
        $this->assertFalse($resolver->isDeliverable(''));
        $this->assertTrue($resolver->isDeliverable('buyer@retail.com'));
        $this->assertTrue($resolver->isDeliverable('seller@nailsfactory.com'));
    }

    public function test_email_notification_service_records_skipped_log_for_invalid_email(): void
    {
        $service = app(EmailNotificationService::class);

        $dispatched = $service->sendRaw(
            recipientEmail: 'guest+abc@guest.b2bhaat.invalid',
            subject: 'Test Subject',
            body: '<p>Test body</p>',
            templateKey: 'test_template'
        );

        $this->assertFalse($dispatched);
        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => 'guest+abc@guest.b2bhaat.invalid',
            'status' => EmailLog::STATUS_SKIPPED,
        ]);
    }
}
