<?php

namespace App\Services\Escrow;

use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class EscrowLedgerService
{
    /**
     * Hold sub-order net earnings in seller's escrow held balance upon customer payment.
     */
    public function holdInEscrow(SubOrder $subOrder): WalletTransaction
    {
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
     * Request payout withdrawal from seller's available balance.
     *
     * @throws Exception
     */
    public function requestPayout(User $seller, float $amount, ?string $note = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new Exception('Payout withdrawal amount must be greater than zero.');
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
