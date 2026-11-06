<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\StoreSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationService
{
    public function __construct(
        protected EmailRecipientResolver $resolver
    ) {}

    /**
     * Determine if email notifications are globally enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) StoreSetting::get('email_enabled', config('mail.enabled', true));
    }

    /**
     * Dispatch an email with validation, deduplication, and database audit logging.
     */
    public function send(
        string $recipientEmail,
        Mailable $mailable,
        string $templateKey,
        ?string $recipientName = null,
        ?Model $related = null,
        bool $bypassDeduplication = false
    ): bool {
        $recipientEmail = trim(strtolower($recipientEmail));

        // 1. Deliverability & Synthetic Guest Rejection
        if (! $this->resolver->isDeliverable($recipientEmail)) {
            EmailLog::create([
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $mailable->subject ?? 'System Notification',
                'template_key' => $templateKey,
                'mailable_class' => get_class($mailable),
                'status' => EmailLog::STATUS_SKIPPED,
                'error_message' => 'Skipped: recipient email is invalid, undeliverable, or a synthetic guest address.',
                'related_type' => $related ? get_class($related) : null,
                'related_id' => $related?->getKey(),
            ]);

            return false;
        }

        // 2. Global Enable/Disable Check
        if (! $this->isEnabled()) {
            EmailLog::create([
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $mailable->subject ?? 'System Notification',
                'template_key' => $templateKey,
                'mailable_class' => get_class($mailable),
                'status' => EmailLog::STATUS_SKIPPED,
                'error_message' => 'Email dispatch is globally disabled in store settings.',
                'related_type' => $related ? get_class($related) : null,
                'related_id' => $related?->getKey(),
            ]);

            return false;
        }

        // 3. Deduplication Guard (60s Cache Lock)
        $lockKey = "email_lock:{$recipientEmail}:{$templateKey}:".($related?->getKey() ?? 'general');
        if (! $bypassDeduplication && ! Cache::add($lockKey, true, 60)) {
            return false; // Duplicate suppressed
        }

        // 4. Create Pending Log Record
        $log = EmailLog::create([
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $mailable->subject ?? 'Notification',
            'template_key' => $templateKey,
            'mailable_class' => get_class($mailable),
            'status' => EmailLog::STATUS_PENDING,
            'related_type' => $related ? get_class($related) : null,
            'related_id' => $related?->getKey(),
        ]);

        try {
            Mail::to($recipientEmail, $recipientName)->queue($mailable);

            $log->update([
                'status' => EmailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error("Email dispatch failure to {$recipientEmail}: {$e->getMessage()}", [
                'template_key' => $templateKey,
                'exception' => $e,
            ]);

            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return false;
        }
    }

    /**
     * Send raw HTML email directly (convenient for test email diagnostics in Admin settings).
     */
    public function sendRaw(
        string $recipientEmail,
        string $subject,
        string $body,
        string $templateKey = 'raw_diagnostic'
    ): bool {
        $recipientEmail = trim(strtolower($recipientEmail));

        if (! $this->resolver->isDeliverable($recipientEmail)) {
            EmailLog::create([
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                'template_key' => $templateKey,
                'mailable_class' => 'raw_html',
                'status' => EmailLog::STATUS_SKIPPED,
                'error_message' => 'Invalid or synthetic guest email address.',
            ]);

            return false;
        }

        $log = EmailLog::create([
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'template_key' => $templateKey,
            'mailable_class' => 'raw_html',
            'status' => EmailLog::STATUS_PENDING,
        ]);

        try {
            Mail::html($body, function ($message) use ($recipientEmail, $subject): void {
                $message->to($recipientEmail)->subject($subject);
            });

            $log->update([
                'status' => EmailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return false;
        }
    }
}
