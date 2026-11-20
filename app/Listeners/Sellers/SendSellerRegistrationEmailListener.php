<?php

declare(strict_types=1);

namespace App\Listeners\Sellers;

use App\Events\Sellers\SellerRegisteredEvent;
use App\Mail\Seller\SellerRegistrationReceivedMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSellerRegistrationEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(SellerRegisteredEvent $event): void
    {
        $profile = $event->profile;
        $seller = $profile->user;

        if (! $seller || ! $this->resolver->isDeliverable($seller->email)) {
            return;
        }

        $this->emailService->send(
            recipientEmail: $seller->email,
            mailable: new SellerRegistrationReceivedMail($profile),
            templateKey: 'seller_registration_received',
            recipientName: $seller->name,
            related: $profile
        );
    }
}
