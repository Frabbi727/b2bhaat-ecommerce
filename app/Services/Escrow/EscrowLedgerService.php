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
     * Hold sub-order net earnings in seller's escrow held balance upon customer payment.
     */
    public function holdInEscrow(SubOrder $subOrder): WalletTransaction
    {
        $existingHold = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_HOLD)
            ->first();

        if ($existingHold) {
            return $existingHold;
        }

        return DB::transaction(function () use ($subOrder) {
            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::firstOrCreate(
                ['seller_id' => $subOrder->seller_id],
                ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
            );

            // Re-lock wallet for update
            $wallet = SellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $netEarnings = $subOrder->net_earnings;

            $wallet->increment('escrow_held_balance', $netEarnings);

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => $subOrder->id,
                'transaction_type' => WalletTransaction::TYPE_ESCROW_HOLD,
                'amount' => $netEarnings,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Escrow hold for Sub-Order #{$subOrder->sub_order_number}",
            ]);
        });
    }

    /**
     * Release held funds from escrow into available balance (e.g. 48h after delivery).
     */
    public function releaseFromEscrow(SubOrder $subOrder): WalletTransaction
    {
        $existingRelease = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
            ->first();

        if ($existingRelease) {
            return $existingRelease;
        }

        return DB::transaction(function () use ($subOrder) {
            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::where('seller_id', $subOrder->seller_id)
                ->lockForUpdate()
                ->firstOrFail();

            $netEarnings = $subOrder->net_earnings;

            if ($wallet->escrow_held_balance < $netEarnings) {
                // If held balance is lower, adjust to the remaining held amount
                $netEarnings = $wallet->escrow_held_balance;
            }

            $wallet->decrement('escrow_held_balance', $netEarnings);
            $wallet->increment('available_balance', $netEarnings);

            $wallet->refresh();

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => $subOrder->id,
                'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
                'amount' => $netEarnings,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Released to available balance for delivered Sub-Order #{$subOrder->sub_order_number}",
            ]);
        });
    }

    /**
     * Credit seller wallet directly for delivered COD sub-orders.
     */
    public function creditDeliveredSubOrder(SubOrder $subOrder): WalletTransaction
    {
        $existingRelease = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
            ->first();

        if ($existingRelease) {
            return $existingRelease;
        }

        return DB::transaction(function () use ($subOrder) {
            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::firstOrCreate(
                ['seller_id' => $subOrder->seller_id],
                ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
            );

            $wallet = SellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $netEarnings = $subOrder->net_earnings;

            if ($wallet->escrow_held_balance >= $netEarnings) {
                $wallet->decrement('escrow_held_balance', $netEarnings);
            } elseif ($wallet->escrow_held_balance > 0) {
                $wallet->update(['escrow_held_balance' => 0.00]);
            }

            $wallet->increment('available_balance', $netEarnings);
            $wallet->refresh();

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => $subOrder->id,
                'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
                'amount' => $netEarnings,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Settled net earnings to wallet for delivered Sub-Order #{$subOrder->sub_order_number}",
            ]);
        });
    }

    /**
     * Refund held escrow funds when an unfulfilled consignment is cancelled.
     */
    public function refundCancelledEscrow(SubOrder $subOrder, string $reason = 'Consignment cancelled'): ?WalletTransaction
    {
        $existingRefund = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_REFUND)
            ->first();

        if ($existingRefund) {
            return $existingRefund;
        }

        $holdTx = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_HOLD)
            ->first();

        if (! $holdTx) {
            return null;
        }

        return DB::transaction(function () use ($subOrder, $holdTx, $reason) {
            /** @var SellerWallet|null $wallet */
            $wallet = SellerWallet::where('seller_id', $subOrder->seller_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                return null;
            }

            $refundAmount = min((float) $holdTx->amount, (float) $wallet->escrow_held_balance);

            if ($refundAmount > 0) {
                $wallet->decrement('escrow_held_balance', $refundAmount);
                $wallet->refresh();
            }

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => $subOrder->id,
                'transaction_type' => WalletTransaction::TYPE_ESCROW_REFUND,
                'amount' => (float) $holdTx->amount,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Escrow hold refunded due to cancellation of Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            ]);
        });
    }

    /**
     * Reverse and refund settlement when an approved return request is processed.
     */
    public function refundReturnedSubOrder(SubOrder $subOrder, string $reason = 'Return approved'): ?WalletTransaction
    {
        $existingReturnRefund = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_REFUND)
            ->first();

        if ($existingReturnRefund) {
            return $existingReturnRefund;
        }

        return DB::transaction(function () use ($subOrder, $reason) {
            /** @var SellerWallet|null $wallet */
            $wallet = SellerWallet::where('seller_id', $subOrder->seller_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                return null;
            }

            $netEarnings = $subOrder->net_earnings;

            if ($wallet->available_balance >= $netEarnings) {
                $wallet->decrement('available_balance', $netEarnings);
            } elseif ($wallet->escrow_held_balance >= $netEarnings) {
                $wallet->decrement('escrow_held_balance', $netEarnings);
            } else {
                $remaining = $netEarnings - (float) $wallet->available_balance;
                $wallet->update(['available_balance' => 0.00]);
                if ($wallet->escrow_held_balance > 0) {
                    $wallet->decrement('escrow_held_balance', min($remaining, (float) $wallet->escrow_held_balance));
                }
            }
            $wallet->refresh();

            return WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'sub_order_id' => $subOrder->id,
                'transaction_type' => WalletTransaction::TYPE_ESCROW_REFUND,
                'amount' => $netEarnings,
                'running_balance' => $wallet->available_balance,
                'reference_note' => "Settlement reversed due to approved return of Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            ]);
        });
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

            if ($wallet->available_balance < $amount) {
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
