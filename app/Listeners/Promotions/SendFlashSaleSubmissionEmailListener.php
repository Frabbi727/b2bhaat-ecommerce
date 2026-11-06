<?php

declare(strict_types=1);

namespace App\Listeners\Promotions;

use App\Events\Promotions\FlashSaleSubmissionDecidedEvent;
use App\Mail\Seller\FlashSaleSubmissionStatusMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendFlashSaleSubmissionEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(FlashSaleSubmissionDecidedEvent $event): void
    {
        $submission = $event->submission;
        $seller = $submission->seller;

        if (! $seller || ! $this->resolver->isDeliverable($seller->email)) {
            return;
        }

        $this->emailService->send(
            recipientEmail: $seller->email,
            mailable: new FlashSaleSubmissionStatusMail($submission, $event->status, $event->feedbackReason),
            templateKey: 'seller_flash_sale_status',
            recipientName: $seller->name,
            related: $submission
        );
    }
}
