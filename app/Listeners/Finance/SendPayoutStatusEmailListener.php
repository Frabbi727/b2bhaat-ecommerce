<?php

declare(strict_types=1);

namespace App\Listeners\Finance;

use App\Events\Finance\PayoutStatusUpdatedEvent;
use App\Mail\Seller\PayoutStatusMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPayoutStatusEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(PayoutStatusUpdatedEvent $event): void
    {
        $payout = $event->payout;
        $wallet = $payout->wallet;
        $seller = $wallet?->seller;

        if (! $seller) {
            return;
        }

        $email = $seller->email;

        if (! $this->resolver->isDeliverable($email)) {
            return;
        }

        $this->emailService->send(
            recipientEmail: $email,
            mailable: new PayoutStatusMail($payout, $event->status, $event->trxIdOrReason),
            templateKey: 'seller_payout_status',
            recipientName: $seller->name,
            related: $payout
        );
    }
}
