<?php

declare(strict_types=1);

namespace App\Mail\Customer;

use App\Mail\BaseMailable;
use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderConfirmationMail extends BaseMailable
{
    public function __construct(
        public Order $order
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'order_confirmation';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Confirmation #{$this->order->order_number} - B2B Haat",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer.order-confirmation',
            with: [
                'order' => $this->order->loadMissing('customer', 'subOrders.items.product', 'paymentMethod'),
                'invoiceUrl' => route('order.invoice.token', ['token' => $this->order->invoice_token]),
                'trackingUrl' => route('order.track.token', ['token' => $this->order->tracking_token]),
                'recipientName' => $this->order->shipping_address['recipient_name'] ?? $this->order->customer?->name ?? 'Valued Customer',
            ],
        );
    }
}
