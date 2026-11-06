<?php

declare(strict_types=1);

namespace App\Mail\Seller;

use App\Mail\BaseMailable;
use App\Models\SellerProfile;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SellerKycDecisionMail extends BaseMailable
{
    public function __construct(
        public SellerProfile $profile,
        public bool $isApproved,
        public ?string $rejectionReason = null
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return $this->isApproved ? 'seller_kyc_approved' : 'seller_kyc_rejected';
    }

    public function envelope(): Envelope
    {
        $status = $this->isApproved ? 'Approved & Activated' : 'Application Status Update';

        return new Envelope(
            subject: "Your Seller Store '{$this->profile->store_name}' {$status} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $seller = $this->profile->user;

        return new Content(
            view: 'emails.seller.kyc-decision',
            with: [
                'profile' => $this->profile,
                'isApproved' => $this->isApproved,
                'rejectionReason' => $this->rejectionReason,
                'ownerName' => $seller?->name ?? 'Merchant',
                'storeName' => $this->profile->store_name,
                'sellerDashboardUrl' => url('/seller'),
            ],
        );
    }
}
