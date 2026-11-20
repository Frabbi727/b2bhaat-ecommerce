<?php

declare(strict_types=1);

namespace App\Mail\Seller;

use App\Mail\BaseMailable;
use App\Models\SubOrder;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewConsignmentOrderMail extends BaseMailable
{
    public function __construct(
        public SubOrder $subOrder
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'seller_new_order';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New Order #{$this->subOrder->sub_order_number} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $sellerOrderUrl = route('filament.seller.resources.sub-orders.view', ['record' => $this->subOrder->id], false);
        $fullOrderUrl = url($sellerOrderUrl);

        return new Content(
            view: 'emails.seller.new-consignment',
            with: [
                'subOrder' => $this->subOrder->loadMissing('items.product', 'seller.sellerProfile', 'order'),
                'sellerOrderUrl' => $fullOrderUrl,
                'sellerName' => $this->subOrder->seller?->name ?? 'Valued Seller',
                'storeName' => $this->subOrder->seller?->sellerProfile?->store_name ?? 'Your Store',
            ],
        );
    }
}
