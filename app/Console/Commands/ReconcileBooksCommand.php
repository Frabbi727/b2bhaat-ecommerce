<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Accounting\BooksDoNotReconcile;
use App\Services\Accounting\AccountingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Roadmap 4.2 / F6: once a day, check that seller wallets, requested payouts, refunds owed, courier cash and the
 * bKash/Nagad wallet all agree with the books. When something does not, every active admin gets one bell entry
 * naming it, so a mistake is found the next morning instead of only when someone opens the reconciliation page.
 */
class ReconcileBooksCommand extends Command
{
    protected $signature = 'accounting:reconcile';

    protected $description = 'Check that wallets, payouts, refunds and courier cash match the books, and tell admins if not';

    public function handle(AccountingReconciliationService $reconciliation): int
    {
        $result = $reconciliation->runAllChecks();

        if ($result['is_reconciled']) {
            $this->info('The books reconcile: nothing to report.');

            return self::SUCCESS;
        }

        $admins = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['super_admin', 'admin']))
            ->where('is_active', true)
            ->get();
        Notification::send($admins, new BooksDoNotReconcile($result['problems']));

        foreach ($result['problems'] as $problem) {
            $this->warn($problem);
        }

        return self::SUCCESS;
    }
}
