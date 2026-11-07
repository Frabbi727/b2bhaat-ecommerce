<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Refund;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;

class AccountingReconciliationService
{
    public function __construct(
        protected ?GeneralLedgerService $generalLedgerService = null,
    ) {
        $this->generalLedgerService ??= app(GeneralLedgerService::class);
    }

    /**
     * Reconcile an individual seller's wallet sub-ledger balances against general ledger liability accounts.
     *
     * @return array{
     *     is_reconciled: bool,
     *     wallet_balance: float,
     *     ledger_balance: float,
     *     discrepancy: float,
     *     escrow_wallet_balance: float,
     *     escrow_ledger_balance: float,
     *     escrow_discrepancy: float,
     *     reserved_wallet_balance: float,
     *     reserved_ledger_balance: float,
     *     reserved_discrepancy: float,
     *     seller_id: int
     * }
     */
    public function verifySellerWallet(User $seller, ?SellerWallet $wallet = null): array
    {
        $wallet ??= SellerWallet::where('seller_id', $seller->id)->first();

        $walletBalance = round((float) ($wallet?->available_balance ?? 0.0), 2);
        $escrowWalletBalance = round((float) ($wallet?->escrow_held_balance ?? 0.0), 2);

        $ledgerBalance = round($this->generalLedgerService->getBalanceForSlug('seller_wallet_payable', sellerId: $seller->id), 2);
        $escrowLedgerBalance = round($this->generalLedgerService->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id), 2);

        $discrepancy = round($walletBalance - $ledgerBalance, 2);
        $escrowDiscrepancy = round($escrowWalletBalance - $escrowLedgerBalance, 2);

        $reservedLedgerBalance = round($this->generalLedgerService->getBalanceForSlug('reserved_payout_payable', sellerId: $seller->id), 2);
        $reservedWalletBalance = $wallet
            ? round((float) WalletTransaction::where('seller_wallet_id', $wallet->id)
                ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
                ->where('status', WalletTransaction::STATUS_REQUESTED)
                ->sum('amount'), 2)
            : 0.0;
        $reservedDiscrepancy = round($reservedWalletBalance - $reservedLedgerBalance, 2);

        $walletBalance = $this->normalizeZero($walletBalance);
        $ledgerBalance = $this->normalizeZero($ledgerBalance);
        $discrepancy = $this->normalizeZero($discrepancy);

        $escrowWalletBalance = $this->normalizeZero($escrowWalletBalance);
        $escrowLedgerBalance = $this->normalizeZero($escrowLedgerBalance);
        $escrowDiscrepancy = $this->normalizeZero($escrowDiscrepancy);

        $reservedWalletBalance = $this->normalizeZero($reservedWalletBalance);
        $reservedLedgerBalance = $this->normalizeZero($reservedLedgerBalance);
        $reservedDiscrepancy = $this->normalizeZero($reservedDiscrepancy);

        $isReconciled = ($discrepancy === 0.00 && $escrowDiscrepancy === 0.00 && $reservedDiscrepancy === 0.00);

        return [
            'is_reconciled' => $isReconciled,
            'wallet_balance' => $walletBalance,
            'ledger_balance' => $ledgerBalance,
            'discrepancy' => $discrepancy,
            'escrow_wallet_balance' => $escrowWalletBalance,
            'escrow_ledger_balance' => $escrowLedgerBalance,
            'escrow_discrepancy' => $escrowDiscrepancy,
            'reserved_wallet_balance' => $reservedWalletBalance,
            'reserved_ledger_balance' => $reservedLedgerBalance,
            'reserved_discrepancy' => $reservedDiscrepancy,
            'seller_id' => (int) $seller->id,
        ];
    }

    /**
     * Reconcile system-wide pending seller payouts against the reserved payout payable liability account.
     *
     * @return array{
     *     is_reconciled: bool,
     *     total_pending_payouts: float,
     *     ledger_reserved_balance: float,
     *     discrepancy: float
     * }
     */
    public function verifyReservedPayouts(): array
    {
        $totalPending = round((float) WalletTransaction::where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->sum('amount'), 2);
        $ledgerBalance = round($this->generalLedgerService->getBalanceForSlug('reserved_payout_payable'), 2);
        $discrepancy = $this->normalizeZero(round($totalPending - $ledgerBalance, 2));

        return [
            'is_reconciled' => $discrepancy === 0.00,
            'total_pending_payouts' => $this->normalizeZero($totalPending),
            'ledger_reserved_balance' => $this->normalizeZero($ledgerBalance),
            'discrepancy' => $discrepancy,
        ];
    }

    /**
     * Reconcile system-wide pending customer refunds against the customer refund payable liability account.
     *
     * @return array{
     *     is_reconciled: bool,
     *     total_pending_refunds: float,
     *     ledger_refund_payable_balance: float,
     *     discrepancy: float
     * }
     */
    public function verifyCustomerRefundPayables(): array
    {
        $totalPending = round((float) Refund::where('status', Refund::STATUS_PENDING)->sum('amount'), 2);
        $ledgerBalance = round($this->generalLedgerService->getBalanceForSlug('customer_refund_payable'), 2);
        $discrepancy = $this->normalizeZero(round($totalPending - $ledgerBalance, 2));

        return [
            'is_reconciled' => $discrepancy === 0.00,
            'total_pending_refunds' => $this->normalizeZero($totalPending),
            'ledger_refund_payable_balance' => $this->normalizeZero($ledgerBalance),
            'discrepancy' => $discrepancy,
        ];
    }

    /**
     * Reconcile wallet balances against general ledger liability accounts for all sellers having a wallet.
     *
     * @return array{
     *     total_sellers: int,
     *     reconciled_count: int,
     *     unreconciled_count: int,
     *     details: list<array{
     *         is_reconciled: bool,
     *         wallet_balance: float,
     *         ledger_balance: float,
     *         discrepancy: float,
     *         escrow_wallet_balance: float,
     *         escrow_ledger_balance: float,
     *         escrow_discrepancy: float,
     *         reserved_wallet_balance: float,
     *         reserved_ledger_balance: float,
     *         reserved_discrepancy: float,
     *         seller_id: int
     *     }>
     * }
     */
    public function verifyAllSellers(): array
    {
        $wallets = SellerWallet::with('seller')->cursor();

        $details = [];
        $reconciledCount = 0;
        $unreconciledCount = 0;

        foreach ($wallets as $wallet) {
            $seller = $wallet->seller;
            if (! $seller) {
                continue;
            }

            $result = $this->verifySellerWallet($seller, $wallet);
            $details[] = $result;

            if ($result['is_reconciled']) {
                $reconciledCount++;
            } else {
                $unreconciledCount++;
            }
        }

        return [
            'total_sellers' => count($details),
            'reconciled_count' => $reconciledCount,
            'unreconciled_count' => $unreconciledCount,
            'details' => $details,
        ];
    }

    /**
     * Normalize negative zeros (-0.0) resulting from floating-point arithmetic.
     */
    private function normalizeZero(float $val): float
    {
        return $val == 0.0 ? 0.00 : $val;
    }
}
