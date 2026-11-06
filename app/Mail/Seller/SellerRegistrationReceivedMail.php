<?php

declare(strict_types=1);

namespace App\Mail\Seller;

use App\Mail\BaseMailable;
use App\Models\SellerProfile;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SellerRegistrationReceivedMail extends BaseMailable
{
    public function __construct(
        public SellerProfile $profile
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'seller_registration_received';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seller Application Received - B2B Haat',
        );
    }

    public function content(): Content
    {
        $seller = $this->profile->user;

        return new Content(
            view: 'emails.seller.registration-received',
            with: [
                'profile' => $this->profile,
                'ownerName' => $seller?->name ?? 'Valued Partner',
                'storeName' => $this->profile->store_name,
                'tradeLicense' => $this->profile->trade_license_number ?? 'Not submitted',
            ],
        );
    }
}
