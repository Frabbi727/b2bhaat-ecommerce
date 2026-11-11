<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerWallet;
use App\Models\WalletTransaction;
use App\Services\SellerAccounting\SellerBooksProjector;
use App\Services\SellerAccounting\SellerChartOfAccountsService;
use App\Services\SellerAccounting\SellerFinancialReportService;
use Illuminate\Console\Command;

/**
 * Seller books: replay wallet history into the sellers' own books. Safe to run any number of times — an entry
 * already recorded is never recorded again — so it also fills any gap left by a failed automatic entry.
 */
class RebuildSellerBooksCommand extends Command
{
    protected $signature = 'seller-books:rebuild
        {--seller= : Only this seller (user id)}
        {--adopt-wallet-differences : Book any wallet money with no history behind it as an opening balance}';

    protected $description = 'Record any wallet activity missing from the sellers\' own books';

    public function handle(SellerBooksProjector $projector, SellerChartOfAccountsService $chart, SellerFinancialReportService $reports): int
    {
        $sellerId = $this->option('seller') !== null ? (int) $this->option('seller') : null;

        $wallets = SellerWallet::query()
            ->when($sellerId !== null, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderBy('id')
            ->get(['id', 'seller_id']);

        if ($wallets->isEmpty()) {
            $this->info('No seller wallets to process.');

            return self::SUCCESS;
        }

        $mismatched = 0;

        foreach ($wallets as $wallet) {
            $chart->ensureFor($wallet->seller_id);

            WalletTransaction::query()
                ->where('seller_wallet_id', $wallet->id)
                ->chunkById(200, function ($transactions) use ($projector): void {
                    foreach ($transactions as $transaction) {
                        $projector->project($transaction);

                        if ($transaction->transaction_type === WalletTransaction::TYPE_PAYOUT
                            && $transaction->status === WalletTransaction::STATUS_DISBURSED) {
                            $projector->projectPayoutDisbursed($transaction);
                        }
                    }
                });

            $check = $reports->walletReconciliation($wallet->seller_id);
            if (! $check['is_consistent'] && $this->option('adopt-wallet-differences')) {
                $entry = $projector->adoptWalletDifference($wallet->seller_id, $check);
                $this->line(sprintf('Seller #%d: wallet difference booked as opening balance (%s).', $wallet->seller_id, $entry?->entry_number ?? 'nothing to book'));
                $check = $reports->walletReconciliation($wallet->seller_id);
            }

            if (! $check['is_consistent']) {
                $mismatched++;
                $this->warn(sprintf(
                    'Seller #%d: books differ from wallet (on hold %s vs %s, available %s vs %s, payouts %s vs %s).',
                    $wallet->seller_id,
                    $check['on_hold_books'], $check['on_hold_wallet'],
                    $check['available_books'], $check['available_wallet'],
                    $check['in_transit_books'], $check['in_transit_wallet'],
                ));
            }
        }

        $this->info(sprintf('Seller books up to date for %d seller(s); %d differ from their wallet.', $wallets->count(), $mismatched));
        if ($mismatched > 0 && ! $this->option('adopt-wallet-differences')) {
            $this->line('A difference means the wallet was changed without a wallet transaction (e.g. seeded or set by hand). Run again with --adopt-wallet-differences to book it as an opening balance.');
        }

        return $mismatched === 0 ? self::SUCCESS : self::FAILURE;
    }
}
