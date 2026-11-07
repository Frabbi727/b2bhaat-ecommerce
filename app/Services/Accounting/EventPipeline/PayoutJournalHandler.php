<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\WalletTransaction;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\Schema;

class PayoutJournalHandler
{
    public function __construct(
        protected ?JournalPostingService $journalPostingService = null,
    ) {
        $this->journalPostingService ??= app(JournalPostingService::class);
    }

    /**
     * Record accounting entry for a requested seller payout (reserve liability).
     */
    public function recordPayoutRequested(WalletTransaction $payout): ?AccountingJournalEntry
    {
        $amount = round((float) $payout->amount, 2);
        if ($amount <= 0.0) {
            return null;
        }

        if (! $this->areAccountsConfigured('seller_wallet_payable', 'reserved_payout_payable')) {
            return null;
        }

        $payout->loadMissing('wallet');
        $sellerId = $payout->wallet?->seller_id;

        $lines = [
            // 1. Debit Seller Available Wallet Payable (decreases available wallet liability)
            new JournalLineData(
                accountSlug: 'seller_wallet_payable',
                debit: $amount,
                credit: 0.00,
                sellerId: $sellerId,
                narration: "Reserve wallet balance for payout request #{$payout->id}",
            ),
            // 2. Credit Reserved Payout Liabilities (increases reserved payout liability)
            new JournalLineData(
                accountSlug: 'reserved_payout_payable',
                debit: 0.00,
                credit: $amount,
                sellerId: $sellerId,
                narration: "Credit reserved payout liability for payout request #{$payout->id}",
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::PAYOUT_REQUESTED->value,
            idempotencyKey: "payout_requested_{$payout->id}",
            description: "Payout requested for wallet transaction #{$payout->id}",
            lines: $lines,
            referenceType: WalletTransaction::class,
            referenceId: $payout->id,
            metadata: array_filter([
                'seller_wallet_id' => $payout->seller_wallet_id,
                'seller_id' => $sellerId,
            ]),
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * Record accounting entry for a disbursed seller payout (settle liability against bank/clearing).
     */
    public function recordPayoutDisbursed(
        WalletTransaction $payout,
        string $channel = 'bank',
        ?string $trxId = null,
    ): ?AccountingJournalEntry {
        $amount = round((float) $payout->amount, 2);
        if ($amount <= 0.0) {
            return null;
        }

        $channelSlug = strtolower(trim($channel));
        $creditAccountSlug = in_array($channelSlug, ['bkash', 'nagad', 'sslcommerz', 'mfs'], true)
            ? 'gateway_clearing'
            : 'bank_operating';

        if (! $this->areAccountsConfigured('reserved_payout_payable', $creditAccountSlug)) {
            return null;
        }

        $payout->loadMissing('wallet');
        $sellerId = $payout->wallet?->seller_id;

        $trxSuffix = $trxId !== null && $trxId !== '' ? " (TrxID: {$trxId})" : '';
        $creditNarration = $creditAccountSlug === 'gateway_clearing'
            ? "MFS payout settlement via {$channel}{$trxSuffix} for payout #{$payout->id}"
            : "Bank operating payout disbursement via {$channel}{$trxSuffix} for payout #{$payout->id}";

        $lines = [
            // 1. Debit Reserved Payout Liabilities (satisfies reserved liability)
            new JournalLineData(
                accountSlug: 'reserved_payout_payable',
                debit: $amount,
                credit: 0.00,
                sellerId: $sellerId,
                narration: "Disburse reserved payout liability for payout request #{$payout->id}",
            ),
            // 2. Credit Bank Operating or Gateway Clearing (decreases platform asset)
            new JournalLineData(
                accountSlug: $creditAccountSlug,
                debit: 0.00,
                credit: $amount,
                narration: $creditNarration,
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::PAYOUT_DISBURSED->value,
            idempotencyKey: "payout_disbursed_{$payout->id}",
            description: "Payout disbursed for wallet transaction #{$payout->id} via {$channel}{$trxSuffix}",
            lines: $lines,
            referenceType: WalletTransaction::class,
            referenceId: $payout->id,
            metadata: array_filter([
                'seller_wallet_id' => $payout->seller_wallet_id,
                'seller_id' => $sellerId,
                'channel' => $channel,
                'trx_id' => $trxId,
            ]),
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * Record accounting entry for a rejected seller payout (restore available wallet liability).
     */
    public function recordPayoutRejected(WalletTransaction $payout, string $reason): ?AccountingJournalEntry
    {
        $amount = round((float) $payout->amount, 2);
        if ($amount <= 0.0) {
            return null;
        }

        if (! $this->areAccountsConfigured('reserved_payout_payable', 'seller_wallet_payable')) {
            return null;
        }

        $payout->loadMissing('wallet');
        $sellerId = $payout->wallet?->seller_id;

        $lines = [
            // 1. Debit Reserved Payout Liabilities (cancels reserved liability)
            new JournalLineData(
                accountSlug: 'reserved_payout_payable',
                debit: $amount,
                credit: 0.00,
                sellerId: $sellerId,
                narration: "Reverse reserved payout liability for rejected payout #{$payout->id}: {$reason}",
            ),
            // 2. Credit Seller Available Wallet Payable (restores available wallet liability)
            new JournalLineData(
                accountSlug: 'seller_wallet_payable',
                debit: 0.00,
                credit: $amount,
                sellerId: $sellerId,
                narration: "Restore available wallet balance for rejected payout #{$payout->id}: {$reason}",
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::PAYOUT_REJECTED->value,
            idempotencyKey: "payout_rejected_{$payout->id}",
            description: "Payout rejected for wallet transaction #{$payout->id}. Reason: {$reason}",
            lines: $lines,
            referenceType: WalletTransaction::class,
            referenceId: $payout->id,
            metadata: [
                'seller_wallet_id' => $payout->seller_wallet_id,
                'seller_id' => $sellerId,
                'rejection_reason' => $reason,
            ],
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * Check if required accounting accounts exist in the chart of accounts.
     */
    protected function areAccountsConfigured(string ...$slugs): bool
    {
        if (! Schema::hasTable('accounting_accounts')) {
            return false;
        }

        if (AccountingAccount::where('system_slug', 'reserved_payout_payable')->doesntExist()) {
            return false;
        }

        foreach ($slugs as $slug) {
            if (AccountingAccount::where('system_slug', $slug)->doesntExist()) {
                return false;
            }
        }

        return true;
    }
}
