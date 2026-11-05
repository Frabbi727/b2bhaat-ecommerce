<?php

namespace App\Services\Escrow;

use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Settings\StoreConfigurationService;
use Exception;
use Illuminate\Support\Facades\DB;

class EscrowLedgerService
{
    public function __construct(
        protected StoreConfigurationService $config
    ) {}

    /**
     * Hold what the platform owes the seller for this sub-order (net earnings, plus the delivery fee when the
     * seller self-delivers — D6). Prepaid orders hold at payment; COD holds at delivery (D2). Calling again tops
     * up a prepaid hold once if the seller later chose self-delivery; otherwise it is a no-op.
     */
    public function holdInEscrow(SubOrder $subOrder): WalletTransaction
    {
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

            $shortfall = round($payable - $this->heldAmountFor($subOrder), 2);
            $alreadySettled = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE)
                || $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_REFUND);

            if ($shortfall > 0 && ! $alreadySettled && ! $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT)) {
                $wallet->increment('escrow_held_balance', $shortfall);
                $wallet->refresh();
                $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT, $shortfall,
                    "Self-delivery fee added to escrow for Sub-Order #{$subOrder->sub_order_number}");
            }

            return $hold;
        });
    }

    /**
     * Total currently attributed to this sub-order's hold (hold plus any self-delivery top-up).
     */
    public function heldAmountFor(SubOrder $subOrder): float
    {
        return round((float) WalletTransaction::where('sub_order_id', $subOrder->id)
            ->whereIn('transaction_type', [WalletTransaction::TYPE_ESCROW_HOLD, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT])
            ->sum('amount'), 2);
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

            return $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_RELEASE, $amount,
                "Released to available balance for delivered Sub-Order #{$subOrder->sub_order_number}");
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

            return $this->record($wallet, $subOrder, WalletTransaction::TYPE_ESCROW_REFUND, $amount,
                "Escrow hold refunded for Sub-Order #{$subOrder->sub_order_number}: {$reason}");
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

            $release = $this->findTransaction($subOrder, WalletTransaction::TYPE_ESCROW_RELEASE);
            if (! $release) {
                return $this->refundCancelledEscrow($subOrder, $reason);
            }

            $amount = round((float) $release->amount, 2);
            $wallet->decrement('available_balance', $amount);
            $wallet->refresh();

            return $this->record($wallet, $subOrder, WalletTransaction::TYPE_RETURN_REVERSAL, $amount,
                "Settlement reversed for returned Sub-Order #{$subOrder->sub_order_number}: {$reason}");
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

            if ((float) $wallet->available_balance <= 0 || $wallet->available_balance < $amount) {
                throw new Exception("Insufficient available balance. Requested: {$amount}, Available: {$wallet->available_balance}");
            }

            $wallet->decrement('available_balance', $amount);
            $wallet->refresh();

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => null,
                'transaction_type' => WalletTransaction::TYPE_PAYOUT,
                'amount' => $amount,
                'running_balance' => $wallet->available_balance,
                'reference_note' => $note ?? 'Seller payout withdrawal request',
            ]);
        });
    }
}
