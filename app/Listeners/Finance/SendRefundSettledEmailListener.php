<?php

declare(strict_types=1);

namespace App\Listeners\Finance;

use App\Events\Finance\RefundSettledEvent;
use App\Mail\Customer\RefundSettledMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendRefundSettledEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(RefundSettledEvent $event): void
    {
        $refund = $event->refund;
        $order = $refund->order;

        if (! $order) {
            return;
        }

        $email = $this->resolver->resolveOrderCustomerEmail($order);

        if (! $email) {
            return;
        }

        $recipientName = $order->shipping_address['recipient_name'] ?? $order->customer?->name ?? 'Valued Customer';

        $this->emailService->send(
            recipientEmail: $email,
            mailable: new RefundSettledMail($refund),
            templateKey: 'customer_refund_settled',
            recipientName: $recipientName,
            related: $refund
        );
    }
}
