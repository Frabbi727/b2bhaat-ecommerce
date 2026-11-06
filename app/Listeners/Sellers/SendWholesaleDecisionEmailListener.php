<?php

declare(strict_types=1);

namespace App\Listeners\Sellers;

use App\Events\Sellers\WholesaleKycDecidedEvent;
use App\Mail\Customer\WholesaleDecisionMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWholesaleDecisionEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(WholesaleKycDecidedEvent $event): void
    {
        $user = $event->user;

        if (! $this->resolver->isDeliverable($user->email)) {
            return;
        }

        $templateKey = $event->isApproved ? 'wholesale_approved' : 'wholesale_rejected';

        $this->emailService->send(
            recipientEmail: $user->email,
            mailable: new WholesaleDecisionMail($user, $event->isApproved, $event->rejectionReason),
            templateKey: $templateKey,
            recipientName: $user->name,
            related: $user
        );
    }
}
