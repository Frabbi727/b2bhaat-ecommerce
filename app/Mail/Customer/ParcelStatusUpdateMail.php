<?php

declare(strict_types=1);

namespace App\Mail\Customer;

use App\Mail\BaseMailable;
use App\Models\SubOrder;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ParcelStatusUpdateMail extends BaseMailable
{
    public function __construct(
        public SubOrder $subOrder,
        public string $statusSlug
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'order_tracking_update';
    }

    public function envelope(): Envelope
    {
        $statusWord = match ($this->statusSlug) {
            'shipped' => 'Dispatched with Courier',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered Successfully',
            'cancelled' => 'Parcel Cancelled',
            default => 'Status Update',
        };

        return new Envelope(
            subject: "Parcel #{$this->subOrder->sub_order_number}: {$statusWord} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $order = $this->subOrder->order;
        $trackingUrl = $order ? route('order.track.token', ['token' => $order->tracking_token]) : route('tracking.index');

        return new Content(
            view: 'emails.customer.parcel-status-update',
            with: [
                'subOrder' => $this->subOrder->loadMissing('seller.sellerProfile', 'order', 'items.product'),
                'statusSlug' => $this->statusSlug,
                'trackingUrl' => $trackingUrl,
                'recipientName' => $order?->shipping_address['recipient_name'] ?? $order?->customer?->name ?? 'Valued Customer',
                'storeName' => $this->subOrder->seller?->sellerProfile?->store_name ?? 'Seller Store',
            ],
        );
    }
}
