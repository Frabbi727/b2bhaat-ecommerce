<?php

declare(strict_types=1);

namespace App\Services\Escrow;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Events\Finance\PayoutStatusUpdatedEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\ReturnRequest;
use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\EventPipeline\EscrowReleaseJournalHandler;
use App\Services\Accounting\EventPipeline\PayoutJournalHandler;
use App\Services\Accounting\EventPipeline\RefundJournalHandler;
use App\Services\Accounting\SettlementService;
use App\Services\Settings\StoreConfigurationService;
use DomainException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EscrowLedgerService
{
    public function __construct(
        protected StoreConfigurationService $config,
        protected ?EscrowReleaseJournalHandler $escrowReleaseJournalHandler = null,
        protected ?RefundJournalHandler $refundJournalHandler = null,
        protected ?PayoutJournalHandler $payoutJournalHandler = null,
    ) {
        $this->escrowReleaseJournalHandler ??= app(EscrowReleaseJournalHandler::class);
        $this->refundJournalHandler ??= app(RefundJournalHandler::class);
        $this->payoutJournalHandler ??= app(PayoutJournalHandler::class);
    }

    /**
     * Hold what the platform owes the seller for this sub-order (net earnings, plus the delivery fee when the
     * seller self-delivers — D6). Prepaid orders hold at payment; COD holds at delivery (D2). Calling again tops
     * up a prepaid hold once if the seller later chose self-delivery; otherwise it is a no-op.
     */
    public function holdInEscrow(SubOrder $subOrder): WalletTransaction
    {
        if ($subOrder->fulfillmentStatus()->value('slug') === 'cancelled') {
            throw new DomainException("Consignment #{$subOrder->sub_order_number} was cancelled, so no money is held for the seller.");
        }

        return DB::transaction(function () use ($subOrder) {
            $wallet = $this->lockWallet($subOrder->seller_id, createIfMissing: true);

            $hold = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_HOLD);
            $payable = round($subOrder->sellerPayableAmount(), 2);

            if (! $hold) {
                $wallet->increment('escrow_held_balance', $payable);
                $wallet->refresh();

                return $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_HOLD, $payable,
                    "Escrow hold for Sub-Order #{$subOrder->sub_order_number}");
            }

            $shortfall = round($payable - $this->grossHeldFor($subOrder), 2);
            $alreadySettled = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE)
                || $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_REFUND);

            if ($shortfall > 0 && ! $alreadySettled && ! $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT)) {
                $wallet->increment('escrow_held_balance', $shortfall);
                $wallet->refresh();
                $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT, $shortfall,
                    "Self-delivery fee added to escrow for Sub-Order #{$subOrder->sub_order_number}");
                $this->escrowReleaseJournalHandler->recordSelfDeliveryFeeTopUp($subOrder, $shortfall);
            }

            return $hold;
        });
    }

    /**
     * Self-delivered COD: the seller collected the buyer's cash at the door, so the platform holds nothing.
     * Instead the seller is charged what they owe the platform: cash collected (goods + fee − coupon share) minus
     * their own share (net earnings + fee). A negative difference (coupon larger than commission) is a credit.
     * Idempotent per sub-order.
     */
    public function settleSelfCollectedCod(SubOrder $subOrder): WalletTransaction
    {
        return DB::transaction(function () use ($subOrder) {
            $wallet = $this->lockWallet($subOrder->seller_id, createIfMissing: true);

            if ($existing = $this->findTransaction($subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED)) {
                return $existing;
            }

            $owedToPlatform = round($subOrder->codCollectAmount() - $subOrder->sellerPayableAmount(), 2);

            $wallet->decrement('available_balance', $owedToPlatform);
            $wallet->refresh();

            return $this->record($wallet, $subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED, $owedToPlatform,
                "Seller collected COD cash for Sub-Order #{$subOrder->sub_order_number}; platform commission due");
        });
    }

    /**
     * Total currently attributed to this sub-order's hold (hold plus any self-delivery top-up), less what item
     * returns already took back out of it (PAY-4).
     */
    public function heldAmountFor(SubOrder $subOrder): float
    {
        $returned = (float) WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_PARTIAL_RETURN_HELD)
            ->sum('amount');

        return round($this->grossHeldFor($subOrder) - $returned, 2);
    }

    private function grossHeldFor(SubOrder $subOrder): float
    {
        return round((float) WalletTransaction::where('sub_order_id', $subOrder->id)
            ->whereIn('transaction_type', [WalletTransaction::TYPE_ESCROW_HOLD, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT])
            ->sum('amount'), 2);
    }

    /**
     * PAY-4: undo the seller side of a return of some items. Still on hold → the hold shrinks; already released →
     * the available balance drops (it may go negative); self-collected COD → the commission share, less the
     * coupon share, is given back. One wallet row and one journal per return.
     *
     * @param  array{seller: float, commission: float, discount: float, refund: float, courier_fee: float, completes: bool}  $amounts
     */
    public function reversePartialReturn(SubOrder $subOrder, ReturnRequest $returnRequest, array $amounts, string $reason): ?WalletTransaction
    {
        return DB::transaction(function () use ($subOrder, $returnRequest, $amounts, $reason): ?WalletTransaction {
            $wallet = $this->lockWallet($subOrder->seller_id, createIfMissing: true);

            $existing = WalletTransaction::where('return_request_id', $returnRequest->id)->first();
            if ($existing) {
                return $existing;
            }

            if ($selfCollected = $this->findTransaction($subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED)) {
                $givenBack = (float) WalletTransaction::where('sub_order_id', $subOrder->id)
                    ->where('transaction_type', WalletTransaction::TYPE_PARTIAL_RETURN_COD)
                    ->sum('amount');
                // Net = commission share given back − coupon share taken back; negative when the coupon is bigger
                // (review R-2). The last return settles whatever is left of the delivery charge.
                $commission = round($amounts['commission'], 2);
                $amount = $amounts['completes']
                    ? round((float) $selfCollected->amount - $givenBack, 2)
                    : round($commission - $amounts['discount'], 2);
                $discount = round($commission - $amount, 2);

                if ($commission == 0.0 && $discount == 0.0) {
                    return null;
                }

                $wallet->increment('available_balance', $amount);
                $wallet->refresh();

                $tx = $this->recordForReturn($wallet, $subOrder, $returnRequest, WalletTransaction::TYPE_PARTIAL_RETURN_COD, $amount,
                    "Commission and coupon share settled for items returned from Sub-Order #{$subOrder->sub_order_number}: {$reason}");
                $this->refundJournalHandler->recordSelfCollectedPartialReturn($subOrder, $returnRequest, $commission, $discount, $reason);

                return $tx;
            }

            $released = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE) !== null;
            $amount = round($amounts['seller'], 2);

            if ($released) {
                $wallet->decrement('available_balance', $amount);
            } else {
                $wallet->decrement('escrow_held_balance', $amount);
            }
            $wallet->refresh();

            $tx = $this->recordForReturn($wallet, $subOrder, $returnRequest,
                $released ? WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED : WalletTransaction::TYPE_PARTIAL_RETURN_HELD,
                $amount,
                "Items returned from Sub-Order #{$subOrder->sub_order_number}: {$reason}");

            $this->refundJournalHandler->recordPartialReturnRefund($subOrder, $returnRequest, $released, $amounts, $reason);

            return $tx;
        });
    }

    private function recordForReturn(SellerWallet $wallet, SubOrder $subOrder, ReturnRequest $returnRequest, string $type, float $amount, string $note): WalletTransaction
    {
        return WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'return_request_id' => $returnRequest->id,
            'transaction_type' => $type,
            'amount' => $amount,
            'running_balance' => $wallet->available_balance,
            'reference_note' => $note,
        ]);
    }

    /**
     * Release exactly this sub-order's own hold into available balance (only the scheduled 48 h release calls this).
     * Returns null when there is no hold, or when the hold was already refunded.
     */
    public function releaseFromEscrow(SubOrder $subOrder): ?WalletTransaction
    {
        return DB::transaction(function () use ($subOrder) {
            $wallet = $this->lockWallet($subOrder->seller_id);
            if (! $wallet) {
                return null;
            }

            if ($existing = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE)) {
                return $existing;
            }

            if ($this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_REFUND)) {
                return null;
            }

            $amount = $this->heldAmountFor($subOrder);
            if ($amount <= 0) {
                return null;
            }

            $wallet->decrement('escrow_held_balance', $amount);
            $wallet->increment('available_balance', $amount);
            $wallet->refresh();

            $tx = $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_RELEASE, $amount,
                "Released to available balance for delivered Sub-Order #{$subOrder->sub_order_number}");

            $this->escrowReleaseJournalHandler->recordEscrowRelease($subOrder, $amount);

            return $tx;
        });
    }

    /**
     * Return this sub-order's own hold to the buyer side when it is cancelled or returned before release.
     */
    public function refundCancelledEscrow(SubOrder $subOrder, string $reason = 'Consignment cancelled'): ?WalletTransaction
    {
        return DB::transaction(function () use ($subOrder, $reason) {
            $wallet = $this->lockWallet($subOrder->seller_id);
            if (! $wallet) {
                return null;
            }

            if ($existing = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_REFUND)) {
                return $existing;
            }

            if ($this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE)) {
                return null;
            }

            $amount = $this->heldAmountFor($subOrder);
            if ($amount <= 0) {
                return null;
            }

            $wallet->decrement('escrow_held_balance', $amount);
            $wallet->refresh();

            $tx = $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_REFUND, $amount,
                "Escrow hold refunded for Sub-Order #{$subOrder->sub_order_number}: {$reason}");

            $this->refundJournalHandler->recordPrepaidCancellationRefund($subOrder, $amount, $reason);

            return $tx;
        });
    }

    /**
     * Undo the seller side of a returned sub-order. Still held → refund the hold. Already released → debit the
     * released amount from available balance, which may go negative (the seller then owes it and cannot request a
     * payout until it is recovered). Never touches other orders' money.
     */
    public function reverseReturnedSubOrder(SubOrder $subOrder, string $reason = 'Return approved'): ?WalletTransaction
    {
        return DB::transaction(function () use ($subOrder, $reason) {
            $wallet = $this->lockWallet($subOrder->seller_id);
            if (! $wallet) {
                return null;
            }

            if ($existing = $this->findTransaction($subOrder, WalletTransaction::TYPE_RETURN_REVERSAL)) {
                return $existing;
            }

            // Self-collected COD: nothing was held; give back the commission charge (once)
            if ($selfCollected = $this->findTransaction($subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED)) {
                if ($existing = $this->findTransaction($subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL)) {
                    return $existing;
                }

                $amount = round((float) $selfCollected->amount, 2);
                $wallet->increment('available_balance', $amount);
                $wallet->refresh();

                $tx = $this->record($wallet, $subOrder, WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL, $amount,
                    "Commission charge reversed for returned Sub-Order #{$subOrder->sub_order_number}: {$reason}");

                $this->refundJournalHandler->recordReturnedSubOrderRefund($subOrder, $reason);

                return $tx;
            }

            $release = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE);
            if (! $release) {
                return $this->refundCancelledEscrow($subOrder, $reason);
            }

            $amount = round((float) $release->amount, 2);
            $wallet->decrement('available_balance', $amount);
            $wallet->refresh();

            $tx = $this->record($wallet, $subOrder, WalletTransaction::TYPE_RETURN_REVERSAL, $amount,
                "Settlement reversed for returned Sub-Order #{$subOrder->sub_order_number}: {$reason}");

            $this->refundJournalHandler->recordReturnedSubOrderRefund($subOrder, $reason);

            return $tx;
        });
    }

    private function lockWallet(int $sellerId, bool $createIfMissing = false): ?SellerWallet
    {
        if ($createIfMissing) {
            SellerWallet::firstOrCreate(['seller_id' => $sellerId], ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]);
        }

        return SellerWallet::where('seller_id', $sellerId)->lockForUpdate()->first();
    }

    private function findTransaction(SubOrder $subOrder, string $type): ?WalletTransaction
    {
        return WalletTransaction::where('sub_order_id', $subOrder->id)->where('transaction_type', $type)->first();
    }

    private function record(SellerWallet $wallet, SubOrder $subOrder, string $type, float $amount, string $note): WalletTransaction
    {
        return WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => $type,
            'amount' => $amount,
            'running_balance' => $wallet->available_balance,
            'reference_note' => $note,
        ]);
    }

    /**
     * Request payout withdrawal from seller's available balance.
     *
     * @throws Exception
     */
    public function requestPayout(User $seller, float $amount, ?string $note = null): WalletTransaction
    {
        $minPayout = $this->config->getMinPayoutAmount();

        if ($amount < $minPayout) {
            throw new Exception("Payout withdrawal amount must be at least ৳{$minPayout}. Requested: ৳{$amount}");
        }

        return DB::transaction(function () use ($seller, $amount, $note) {
            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::where('seller_id', $seller->id)
                ->lockForUpdate()
                ->firstOrFail();

            // MON-12: an overdue marketplace bill is paid before money leaves the platform.
            $overdue = SellerInvoice::where('seller_id', $seller->id)
                ->where('status', SellerInvoice::STATUS_UNPAID)
                ->whereDate('due_date', '<', now()->toDateString())
                ->orderBy('due_date')
                ->first();

            if ($overdue) {
                throw new DomainException(sprintf(
                    'Bill %s for ৳%s was due on %s and is still unpaid. Pay it on Subscription & Billing (you can pay from your balance), then request your payout.',
                    $overdue->invoice_number,
                    number_format((float) $overdue->total_amount, 2),
                    $overdue->due_date->format('d M Y'),
                ));
            }

            if ((float) $wallet->available_balance <= 0 || $wallet->available_balance < $amount) {
                throw new Exception("Insufficient available balance. Requested: {$amount}, Available: {$wallet->available_balance}");
            }

            $wallet->decrement('available_balance', $amount);
            $wallet->refresh();

            $transaction = WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => null,
                'transaction_type' => WalletTransaction::TYPE_PAYOUT,
                'status' => WalletTransaction::STATUS_REQUESTED,
                'amount' => $amount,
                'running_balance' => $wallet->available_balance,
                'reference_note' => $note ?? 'Seller payout withdrawal request',
            ]);

            $this->payoutJournalHandler->recordPayoutRequested($transaction);

            return $transaction;
        });
    }

    /**
     * Mark a requested payout as paid out by the platform, recording the MFS/bank TrxID.
     *
     * @param  string  $channel  Disbursement channel ('bank', 'bkash', 'nagad', 'sslcommerz', etc.)
     *
     * @throws Exception
     */
    public function disbursePayout(WalletTransaction $payout, string $trxId, string $channel = 'bank'): void
    {
        $trxId = trim($trxId);
        if ($trxId === '') {
            throw new Exception('A transaction ID is required to disburse a payout.');
        }

        // UX-ACC-3: one transfer reference settles one payout per channel; the lock stops two admins racing
        Cache::lock('payout-disbursement', 15)->block(10, fn () => DB::transaction(function () use ($payout, $trxId, $channel): void {
            $this->assertTrxIdUnused($trxId, $channel);
            $locked = $this->lockPendingPayout($payout);

            $locked->update([
                'status' => WalletTransaction::STATUS_DISBURSED,
                'reference_note' => trim(($locked->reference_note ?? '')." [DISBURSED - TrxID: {$trxId}]"),
            ]);

            $this->payoutJournalHandler->recordPayoutDisbursed($locked, $channel, $trxId);

            DB::afterCommit(function () use ($payout, $trxId): void {
                try {
                    event(new PayoutStatusUpdatedEvent($payout->fresh(), 'disbursed', $trxId));
                } catch (\Throwable $e) {
                    Log::warning('Payout disbursed email event failed: '.$e->getMessage());
                }
            });
        }));
    }

    /**
     * @throws Exception
     */
    private function assertTrxIdUnused(string $trxId, string $channel): void
    {
        $key = SettlementService::referenceKey($trxId);

        $existing = AccountingJournalEntry::query()
            ->where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('status', JournalEntryStatus::POSTED)
            ->where('metadata->channel', $channel)
            ->where(fn ($query) => $query->where('metadata->trx_key', $key)->orWhere('metadata->trx_id', $trxId))
            ->first();

        if ($existing !== null) {
            throw new Exception("TrxID {$trxId} was already used for payout #{$existing->reference_id} ({$existing->journal_number}). Check the transfer and enter its own TrxID.");
        }
    }

    /**
     * Refuse a requested payout and credit the amount back to the seller's available balance.
     *
     * @throws Exception
     */
    public function rejectPayout(WalletTransaction $payout, string $reason): WalletTransaction
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception('A reason is required to reject a payout.');
        }

        return DB::transaction(function () use ($payout, $reason): WalletTransaction {
            $locked = $this->lockPendingPayout($payout);

            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::whereKey($locked->seller_wallet_id)->lockForUpdate()->firstOrFail();
            $wallet->increment('available_balance', (float) $locked->amount);
            $wallet->refresh();

            $locked->update([
                'status' => WalletTransaction::STATUS_REJECTED,
                'reference_note' => trim(($locked->reference_note ?? '')." [REJECTED - {$reason}]"),
            ]);

            $this->payoutJournalHandler->recordPayoutRejected($locked, $reason);

            DB::afterCommit(function () use ($payout, $reason): void {
                try {
                    event(new PayoutStatusUpdatedEvent($payout->fresh(), 'rejected', $reason));
                } catch (\Throwable $e) {
                    Log::warning('Payout rejected email event failed: '.$e->getMessage());
                }
            });

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => null,
                'transaction_type' => WalletTransaction::TYPE_PAYOUT_REVERSAL,
                'amount' => (float) $locked->amount,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Payout #{$locked->id} rejected: {$reason}",
            ]);
        });
    }

    /**
     * @throws Exception
     */
    private function lockPendingPayout(WalletTransaction $payout): WalletTransaction
    {
        /** @var WalletTransaction $locked */
        $locked = WalletTransaction::whereKey($payout->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPendingPayout()) {
            throw new Exception("Payout #{$locked->id} is not awaiting disbursement (status: ".($locked->status ?? 'none').').');
        }

        return $locked;
    }
}
