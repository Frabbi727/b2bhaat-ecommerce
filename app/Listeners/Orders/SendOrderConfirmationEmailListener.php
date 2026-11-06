<?php

declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Events\Orders\OrderPlacedEvent;
use App\Mail\Customer\OrderConfirmationMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderConfirmationEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(OrderPlacedEvent $event): void
    {
        $order = $event->order;
        $customer = $order->customer;

        $email = $this->resolver->resolveOrderCustomerEmail($order);

        if (! $email) {
            return;
        }

        $recipientName = $order->shipping_address['recipient_name'] ?? $customer?->name ?? 'Valued Customer';

        $this->emailService->send(
            recipientEmail: $email,
            mailable: new OrderConfirmationMail($order),
            templateKey: 'order_confirmation',
            recipientName: $recipientName,
            related: $order
        );
    }
}
