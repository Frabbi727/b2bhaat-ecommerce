<?php

declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Events\Orders\ConsignmentStatusUpdatedEvent;
use App\Mail\Customer\ParcelStatusUpdateMail;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendParcelTrackingEmailListener implements ShouldQueue
{
    public const NOTIFIABLE_STATUSES = [
        'shipped',
        'out_for_delivery',
        'delivered',
        'cancelled',
    ];

    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(ConsignmentStatusUpdatedEvent $event): void
    {
        $statusSlug = $event->statusSlug;

        if (! in_array($statusSlug, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        $subOrder = $event->subOrder;
        $order = $subOrder->order;

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
            mailable: new ParcelStatusUpdateMail($subOrder, $statusSlug),
            templateKey: 'order_tracking_update',
            recipientName: $recipientName,
            related: $subOrder
        );
    }
}
