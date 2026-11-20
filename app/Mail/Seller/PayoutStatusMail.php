<?php

declare(strict_types=1);

namespace App\Mail\Seller;

use App\Mail\BaseMailable;
use App\Models\WalletTransaction;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PayoutStatusMail extends BaseMailable
{
    public function __construct(
        public WalletTransaction $payout,
        public string $status,
        public ?string $trxIdOrReason = null
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return 'seller_payout_status';
    }

    public function envelope(): Envelope
    {
        $statusWord = $this->status === 'disbursed' ? 'Disbursed Successfully' : 'Request Update';

        return new Envelope(
            subject: 'Seller Payout ৳'.number_format((float) $this->payout->amount, 2)." {$statusWord} - B2B Haat",
        );
    }

    public function content(): Content
    {
        $wallet = $this->payout->wallet;
        $seller = $wallet?->seller;

        return new Content(
            view: 'emails.seller.payout-status',
            with: [
                'payout' => $this->payout,
                'status' => $this->status,
                'trxIdOrReason' => $this->trxIdOrReason,
                'sellerName' => $seller?->name ?? 'Valued Seller',
                'walletUrl' => url('/seller/seller-wallets'),
            ],
        );
    }
}
