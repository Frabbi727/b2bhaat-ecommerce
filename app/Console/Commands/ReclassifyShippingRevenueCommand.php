<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * ACC-1: order journals used to credit the courier shipping fee to "Courier Shipping Expenses", which hid
 * shipping income and understated costs. This posts one correcting entry per affected journal, dated today,
 * moving those amounts to "Platform Logistics / Shipping Revenue". Posted journals are never edited, and
 * running it again posts nothing new.
 */
class ReclassifyShippingRevenueCommand extends Command
{
    protected $signature = 'accounting:reclassify-shipping {--dry-run : List what would be corrected without posting anything}';

    protected $description = 'Move shipping fees that order journals booked to the courier expense account into shipping revenue';

    /**
     * Automatic order journals that booked the shipping fee to the expense account.
     *
     * @var list<string>
     */
    private const AFFECTED_EVENTS = [
        'order_prepaid_received',
        'cod_delivered',
        'refund_processed',
    ];

    public function handle(JournalPostingService $journalPostingService): int
    {
        $expenseAccount = AccountingAccount::where('system_slug', 'courier_delivery_expense')->first();
        $revenueAccount = AccountingAccount::where('system_slug', 'shipping_service_revenue')->first();

        if (! $expenseAccount || ! $revenueAccount) {
            $this->error('The chart of accounts is missing the courier expense or shipping revenue account. Nothing was changed.');

            return self::FAILURE;
        }

        $entries = $this->affectedEntries($expenseAccount->id);
        $isDryRun = (bool) $this->option('dry-run');
        $posted = 0;
        $alreadyDone = 0;
        $total = 0.0;

        foreach ($entries as $entry) {
            $key = "shipping_reclassified_{$entry->id}";

            if (AccountingJournalEntry::where('idempotency_key', $key)->exists()) {
                $alreadyDone++;

                continue;
            }

            $lines = [];
            foreach ($entry->lines->where('account_id', $expenseAccount->id) as $line) {
                $credit = round((float) $line->credit, 2);
                $debit = round((float) $line->debit, 2);
                $total += $credit - $debit;

                $common = [
                    'sellerId' => $line->seller_id,
                    'customerId' => $line->customer_id,
                    'subOrderId' => $line->sub_order_id,
                    'orderId' => $line->order_id,
                    'narration' => "Shipping reclassified from {$entry->journal_number}",
                ];

                if ($credit > 0.0) {
                    $lines[] = new JournalLineData(...$common, accountId: $expenseAccount->id, debit: $credit);
                    $lines[] = new JournalLineData(...$common, accountId: $revenueAccount->id, credit: $credit);
                }

                if ($debit > 0.0) {
                    $lines[] = new JournalLineData(...$common, accountId: $revenueAccount->id, debit: $debit);
                    $lines[] = new JournalLineData(...$common, accountId: $expenseAccount->id, credit: $debit);
                }
            }

            if ($lines === []) {
                continue;
            }

            if ($isDryRun) {
                $this->line("Would correct {$entry->journal_number} ({$entry->financial_event})");
                $posted++;

                continue;
            }

            $journalPostingService->post(new JournalEntryData(
                entryDate: JournalEntryData::businessDate(),
                financialEvent: AccountingEvent::SHIPPING_RECLASSIFIED->value,
                idempotencyKey: $key,
                description: "Shipping fee moved from courier expense to shipping revenue for {$entry->journal_number}",
                lines: $lines,
                referenceType: AccountingJournalEntry::class,
                referenceId: $entry->id,
                metadata: ['original_journal_id' => $entry->id, 'issue' => 'ACC-1'],
            ));
            $posted++;
        }

        $verb = $isDryRun ? 'would be corrected' : 'corrected';
        $this->info("{$posted} journal(s) {$verb}, {$alreadyDone} already corrected earlier. Net shipping moved to revenue: ৳".number_format($total, 2).'.');

        return self::SUCCESS;
    }

    /**
     * Automatic order journals with lines on the courier expense account, plus the reversals of those journals.
     *
     * @return Collection<int, AccountingJournalEntry>
     */
    private function affectedEntries(int $expenseAccountId): Collection
    {
        $hasExpenseLine = fn ($query) => $query->where('account_id', $expenseAccountId);

        $originals = AccountingJournalEntry::whereIn('financial_event', self::AFFECTED_EVENTS)
            ->whereHas('lines', $hasExpenseLine)
            ->with('lines')
            ->orderBy('id')
            ->get();

        $reversals = AccountingJournalEntry::whereIn('id', $originals->pluck('reversal_journal_id')->filter())
            ->whereHas('lines', $hasExpenseLine)
            ->with('lines')
            ->orderBy('id')
            ->get();

        return $originals->concat($reversals)->sortBy('id')->values();
    }
}
