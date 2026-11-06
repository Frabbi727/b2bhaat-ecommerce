<?php

declare(strict_types=1);

namespace App\Mail\Customer;

use App\Mail\BaseMailable;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WholesaleDecisionMail extends BaseMailable
{
    public function __construct(
        public User $user,
        public bool $isApproved,
        public ?string $rejectionReason = null
    ) {
        parent::__construct();
    }

    public function templateKey(): string
    {
        return $this->isApproved ? 'wholesale_approved' : 'wholesale_rejected';
    }

    public function envelope(): Envelope
    {
        $status = $this->isApproved ? 'Wholesale Account Approved' : 'Wholesale Application Update';

        return new Envelope(
            subject: "{$status} - B2B Haat",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer.wholesale-decision',
            with: [
                'user' => $this->user,
                'isApproved' => $this->isApproved,
                'rejectionReason' => $this->rejectionReason,
                'customerName' => $this->user->name,
                'catalogUrl' => route('catalog.index'),
                'reapplyUrl' => route('wholesale.upgrade'),
            ],
        );
    }
}
