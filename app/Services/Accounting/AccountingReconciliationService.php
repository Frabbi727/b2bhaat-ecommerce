<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Refund;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Formatting\Money;

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
     * ACC-17: the courier COD receivable must equal the cash still owed on delivered courier COD parcels that have
     * not been settled. A gap means a receivable was booked or cleared without a parcel behind it.
     *
     * @return array{
     *     is_reconciled: bool,
     *     unsettled_parcels_total: float,
     *     ledger_balance: float,
     *     discrepancy: float
     * }
     */
    public function verifyCourierCodReceivable(): array
    {
        $parcelsTotal = round((float) app(SettlementService::class)->unsettledCourierParcels()->sum('amount'), 2);
        $ledgerBalance = round($this->generalLedgerService->getBalanceForSlug('courier_cod_receivable'), 2);
        $discrepancy = $this->normalizeZero(round($parcelsTotal - $ledgerBalance, 2));

        return [
            'is_reconciled' => $discrepancy === 0.00,
            'unsettled_parcels_total' => $this->normalizeZero($parcelsTotal),
            'ledger_balance' => $this->normalizeZero($ledgerBalance),
            'discrepancy' => $discrepancy,
        ];
    }

    /**
     * ACC-17: payment gateways can't hold less than nothing for the platform. A negative clearing balance means more
     * was recorded as transferred, refunded or paid out through a gateway than was ever received into it.
     *
     * @return array{
     *     is_reconciled: bool,
     *     ledger_balance: float,
     *     discrepancy: float
     * }
     */
    public function verifyGatewayClearing(): array
    {
        $ledgerBalance = $this->normalizeZero(round($this->generalLedgerService->getBalanceForSlug('gateway_clearing'), 2));
        $discrepancy = $ledgerBalance < 0 ? $ledgerBalance : 0.00;

        return [
            'is_reconciled' => $discrepancy === 0.00,
            'ledger_balance' => $ledgerBalance,
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
        // F6 / roadmap 4.2: three queries for every seller together (wallets, ledger balances, requested payouts)
        // instead of five per seller, so the daily check and the page stay light as sellers grow.
        $wallets = SellerWallet::query()
            ->join('users', 'users.id', '=', 'seller_wallets.seller_id')
            ->orderBy('seller_wallets.seller_id')
            ->get(['seller_wallets.id', 'seller_wallets.seller_id', 'seller_wallets.available_balance', 'seller_wallets.escrow_held_balance']);

        $ledger = app(SellerBalanceService::class)->balancesBySeller();

        $requested = WalletTransaction::query()
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->groupBy('seller_wallet_id')
            ->selectRaw('seller_wallet_id, COALESCE(SUM(amount), 0) as total')
            ->pluck('total', 'seller_wallet_id');

        $details = [];
        $reconciledCount = 0;
        $unreconciledCount = 0;

        foreach ($wallets as $wallet) {
            $sellerId = (int) $wallet->seller_id;
            $balances = $ledger[$sellerId] ?? ['escrow' => 0.0, 'wallet' => 0.0, 'reserved' => 0.0];

            $walletBalance = round((float) $wallet->available_balance, 2);
            $escrowWalletBalance = round((float) $wallet->escrow_held_balance, 2);
            $reservedWalletBalance = round((float) ($requested[$wallet->id] ?? 0.0), 2);

            $discrepancy = $this->normalizeZero(round($walletBalance - $balances['wallet'], 2));
            $escrowDiscrepancy = $this->normalizeZero(round($escrowWalletBalance - $balances['escrow'], 2));
            $reservedDiscrepancy = $this->normalizeZero(round($reservedWalletBalance - $balances['reserved'], 2));
            $isReconciled = $discrepancy === 0.00 && $escrowDiscrepancy === 0.00 && $reservedDiscrepancy === 0.00;

            $details[] = [
                'is_reconciled' => $isReconciled,
                'wallet_balance' => $this->normalizeZero($walletBalance),
                'ledger_balance' => $this->normalizeZero(round($balances['wallet'], 2)),
                'discrepancy' => $discrepancy,
                'escrow_wallet_balance' => $this->normalizeZero($escrowWalletBalance),
                'escrow_ledger_balance' => $this->normalizeZero(round($balances['escrow'], 2)),
                'escrow_discrepancy' => $escrowDiscrepancy,
                'reserved_wallet_balance' => $this->normalizeZero($reservedWalletBalance),
                'reserved_ledger_balance' => $this->normalizeZero(round($balances['reserved'], 2)),
                'reserved_discrepancy' => $reservedDiscrepancy,
                'seller_id' => $sellerId,
            ];

            $isReconciled ? $reconciledCount++ : $unreconciledCount++;
        }

        return [
            'total_sellers' => count($details),
            'reconciled_count' => $reconciledCount,
            'unreconciled_count' => $unreconciledCount,
            'details' => $details,
        ];
    }

    /**
     * Every check at once, for the daily job and the admin alert. A check that fails names what is wrong in plain
     * words.
     *
     * @return array{is_reconciled: bool, problems: list<string>}
     */
    public function runAllChecks(): array
    {
        $problems = [];

        $sellers = $this->verifyAllSellers();
        if ($sellers['unreconciled_count'] > 0) {
            $problems[] = $sellers['unreconciled_count'].' seller wallet(s) do not match the books';
        }

        $payouts = $this->verifyReservedPayouts();
        if (! $payouts['is_reconciled']) {
            $problems[] = 'Requested payouts differ from the books by '.Money::taka($payouts['discrepancy']);
        }

        $refunds = $this->verifyCustomerRefundPayables();
        if (! $refunds['is_reconciled']) {
            $problems[] = 'Refunds owed to buyers differ from the books by '.Money::taka($refunds['discrepancy']);
        }

        $courier = $this->verifyCourierCodReceivable();
        if (! $courier['is_reconciled']) {
            $problems[] = 'Cash couriers owe us differs from the books by '.Money::taka($courier['discrepancy']);
        }

        $mfs = $this->verifyGatewayClearing();
        if (! $mfs['is_reconciled']) {
            $problems[] = 'The bKash / Nagad wallet is below zero in the books ('.Money::taka($mfs['ledger_balance']).')';
        }

        return ['is_reconciled' => $problems === [], 'problems' => $problems];
    }

    /**
     * Normalize negative zeros (-0.0) resulting from floating-point arithmetic.
     */
    private function normalizeZero(float $val): float
    {
        return $val == 0.0 ? 0.00 : $val;
    }
}
