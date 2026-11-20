<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\WalletTransaction;
use App\Services\SellerAccounting\SellerBooksProjector;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Seller books: every wallet movement is copied into the seller's own books once the wallet change has committed,
 * so a rolled-back wallet change never leaves an entry behind.
 */
class WalletTransactionObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SellerBooksProjector $projector,
    ) {}

    public function created(WalletTransaction $transaction): void
    {
        $this->projector->project($transaction);
    }

    public function updated(WalletTransaction $transaction): void
    {
        if ($transaction->transaction_type === WalletTransaction::TYPE_PAYOUT
            && $transaction->wasChanged('status')
            && $transaction->status === WalletTransaction::STATUS_DISBURSED) {
            $this->projector->projectPayoutDisbursed($transaction);
        }
    }
}
