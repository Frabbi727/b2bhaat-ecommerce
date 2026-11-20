<?php

declare(strict_types=1);

namespace App\Mail\Seller;

use App\Mail\BaseMailable;
use App\Models\FlashSaleProduct;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FlashSaleSubmissionStatusMail extends BaseMailable
{
    public function __construct(
        public FlashSaleProduct $submission,
        public string $status,
        public ?string $feedbackReason = null
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'seller_flash_sale_status';
    }

    public function envelope(): Envelope
    {
        $statusWord = match ($this->status) {
            'approved' => 'Approved',
            'revision_requested' => 'Revision Requested',
            'rejected' => 'Not Accepted',
            default => 'Status Update',
        };

        $campaignName = $this->submission->flashSale?->name ?? 'Campaign';

        return new Envelope(
            subject: "Flash Sale '{$campaignName}' Submission {$statusWord} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $seller = $this->submission->seller;
        $product = $this->submission->product;
        $campaign = $this->submission->flashSale;

        return new Content(
            view: 'emails.seller.flash-sale-status',
            with: [
                'submission' => $this->submission,
                'status' => $this->status,
                'feedbackReason' => $this->feedbackReason,
                'sellerName' => $seller?->name ?? 'Valued Seller',
                'productTitle' => $product?->title ?? $product?->name ?? 'Product Item',
                'campaignName' => $campaign?->name ?? 'Flash Sale Campaign',
                'sellerCampaignUrl' => url('/seller/flash-sales'),
            ],
        );
    }
}
