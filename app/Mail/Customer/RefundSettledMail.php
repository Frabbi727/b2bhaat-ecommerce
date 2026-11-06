<?php

declare(strict_types=1);

namespace App\Mail\Customer;

use App\Mail\BaseMailable;
use App\Models\Refund;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RefundSettledMail extends BaseMailable
{
    public function __construct(
        public Refund $refund
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'customer_refund_settled';
    }

    public function envelope(): Envelope
    {
        $orderNumber = $this->refund->order?->order_number ?? 'Order';

        return new Envelope(
            subject: 'Refund of ৳'.number_format((float) $this->refund->amount, 2)." Processed for #{$orderNumber} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $order = $this->refund->order;

        return new Content(
            view: 'emails.customer.refund-settled',
            with: [
                'refund' => $this->refund->loadMissing('order.customer', 'subOrder'),
                'recipientName' => $order?->shipping_address['recipient_name'] ?? $order?->customer?->name ?? 'Valued Customer',
                'orderNumber' => $order?->order_number ?? 'N/A',
                'methodName' => ucfirst($this->refund->method ?? 'MFS/Bank'),
                'reference' => $this->refund->reference ?? 'Direct Settlement',
            ],
        );
    }
}
