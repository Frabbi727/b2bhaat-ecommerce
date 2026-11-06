<?php

declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Events\Orders\OrderPlacedEvent;
use App\Mail\Seller\NewConsignmentOrderMail;
use App\Models\Order;
use App\Services\Email\EmailNotificationService;
use App\Services\Email\EmailRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSellerNewConsignmentEmailListener implements ShouldQueue
{
    public function __construct(
        protected EmailNotificationService $emailService,
        protected EmailRecipientResolver $resolver
    ) {}

    public function handle(OrderPlacedEvent $event): void
    {
        $order = $event->order;

        // Digital payments awaiting verification only reach the seller once verified
        if ($order->paymentStatus?->slug === 'awaiting_verification') {
            return;
        }

        $this->notifySellers($order);
    }

    public function notifySellers(Order $order): void
    {
        foreach ($order->subOrders()->with('seller.sellerProfile', 'items.product')->get() as $subOrder) {
            $sellerEmail = $subOrder->seller?->email;

            if (! $this->resolver->isDeliverable($sellerEmail)) {
                continue;
            }

            $this->emailService->send(
                recipientEmail: $sellerEmail,
                mailable: new NewConsignmentOrderMail($subOrder),
                templateKey: 'seller_new_order',
                recipientName: $subOrder->seller?->name,
                related: $subOrder
            );
        }
    }
}
