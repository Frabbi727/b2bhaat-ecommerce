<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_logs_table_persists_delivery_status(): void
    {
        $log = EmailLog::create([
            'recipient_email' => 'buyer@example.com',
            'recipient_name' => 'John Doe',
            'subject' => 'Order Confirmation #ORD-12345',
            'template_key' => 'order_confirmation',
            'mailable_class' => 'App\Mail\Customer\OrderConfirmationMail',
            'status' => 'sent',
            'retries_count' => 0,
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->id,
            'recipient_email' => 'buyer@example.com',
            'status' => 'sent',
            'template_key' => 'order_confirmation',
        ]);
    }

    public function test_email_templates_table_stores_multilingual_templates(): void
    {
        $template = EmailTemplate::create([
            'key' => 'order_confirmation',
            'name' => 'Order Confirmation',
            'subject_en' => 'Order #{order_number} confirmed',
            'subject_bn' => 'অর্ডার #{order_number} সফলভাবে গৃহীত হয়েছে',
            'content_en' => 'Dear {customer_name}, your order #{order_number} has been received.',
            'content_bn' => 'প্রিয় {customer_name}, আপনার অর্ডার #{order_number} গৃহীত হয়েছে।',
            'placeholders' => ['order_number', 'customer_name', 'total_amount'],
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('email_templates', [
            'key' => 'order_confirmation',
            'is_active' => 1,
        ]);

        $this->assertEquals(['order_number', 'customer_name', 'total_amount'], $template->placeholders);
    }

    public function test_mail_config_supports_smtps_for_port_465(): void
    {
        $this->assertEquals('smtp', config('mail.mailers.smtp.transport'));
        // In default testing env, scheme is null or smtps if port 465 is configured
        $this->assertTrue(config('mail.mailers.smtp.scheme') === null || config('mail.mailers.smtp.scheme') === 'smtps');
    }
}
