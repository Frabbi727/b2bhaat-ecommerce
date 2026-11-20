<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\SettlementException;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F7 (finance audit 2026-11-20): the owner records the platform's own running costs (hosting, SMS, staff,
 * marketing, other) so profit is after costs. Each cost is one journal: the cost account up, the account it was
 * paid from down. A cost recorded by mistake is reversed from the journal entries list, never deleted.
 */
class RunningCostService
{
    /** @var array<string, string> category slug => label shown to the owner */
    public const CATEGORIES = [
        'hosting_expense' => 'Hosting & domain',
        'sms_expense' => 'SMS',
        'staff_expense' => 'Staff',
        'marketing_expense' => 'Marketing & ads',
        'other_running_expense' => 'Other',
    ];

    /** @var array<string, string> account slug => label */
    public const PAID_FROM = [
        'bank_operating' => 'Bank',
        'cash_on_hand' => 'Cash in hand',
        'gateway_clearing' => 'bKash / Nagad wallet',
    ];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @param  string  $submissionKey  one value per form opening, so a double click records the cost once
     *
     * @throws SettlementException
     */
    public function record(
        string $category,
        float $amount,
        string $paidFrom,
        string $date,
        string $note,
        User $actor,
        string $submissionKey,
    ): AccountingJournalEntry {
        if (! array_key_exists($category, self::CATEGORIES)) {
            throw new SettlementException('Choose what the cost was for.');
        }

        if (! array_key_exists($paidFrom, self::PAID_FROM)) {
            throw new SettlementException('Choose where the money was paid from.');
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new SettlementException('Enter an amount above zero.');
        }

        $today = Carbon::now(config('app.display_timezone'))->toDateString();
        if ($date > $today) {
            throw new SettlementException('A cost can\'t be dated in the future.');
        }

        $label = self::CATEGORIES[$category];
        $note = trim($note);
        $description = "Running cost: {$label}".($note !== '' ? " ({$note})" : '');

        $entry = DB::transaction(fn (): AccountingJournalEntry => $this->journalPostingService->post(new JournalEntryData(
            entryDate: $date,
            financialEvent: AccountingEvent::RUNNING_COST_PAID->value,
            idempotencyKey: 'running_cost_'.$submissionKey,
            description: $description,
            lines: [
                new JournalLineData(accountSlug: $category, debit: $amount, narration: $label),
                new JournalLineData(accountSlug: $paidFrom, credit: $amount, narration: 'Paid from '.strtolower(self::PAID_FROM[$paidFrom])),
            ],
            createdByUserId: $actor->id,
            metadata: ['category' => $category, 'paid_from' => $paidFrom, 'note' => $note],
        )));

        if ($entry->wasRecentlyCreated) {
            $this->auditService->record([
                'module' => 'accounting',
                'action' => 'running_cost_recorded',
                'description' => "Recorded {$description}: ৳".number_format($amount, 2),
                'auditable_type' => AccountingJournalEntry::class,
                'auditable_id' => $entry->id,
                'status' => 'success',
                'metadata' => ['journal_number' => $entry->journal_number, 'amount' => $amount, 'category' => $category],
                'sync' => true,
            ]);
        }

        return $entry;
    }

    /**
     * Whether any running cost is recorded in the period, so the profit card can say if costs are missing.
     */
    public function hasCostsBetween(string $from, string $to): bool
    {
        return AccountingJournalEntry::query()
            ->where('financial_event', AccountingEvent::RUNNING_COST_PAID->value)
            ->where('status', JournalEntryStatus::POSTED->value)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->exists();
    }
}
