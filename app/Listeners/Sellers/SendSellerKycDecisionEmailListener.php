<?php

declare(strict_types=1);

namespace App\Listeners\Sellers;

use App\Events\Sellers\SellerKycDecidedEvent;
use App\Mail\Seller\SellerKycDecisionMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSellerKycDecisionEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(SellerKycDecidedEvent $event): void
    {
        $profile = $event->profile;
        $seller = $profile->user;

        if (! $seller || ! $this->resolver->isDeliverable($seller->email)) {
            return;
        }

        $templateKey = $event->isApproved ? 'seller_kyc_approved' : 'seller_kyc_rejected';

        $this->emailService->send(
            recipientEmail: $seller->email,
            mailable: new SellerKycDecisionMail($profile, $event->isApproved, $event->rejectionReason),
            templateKey: $templateKey,
            recipientName: $seller->name,
            related: $profile
        );
    }
}
