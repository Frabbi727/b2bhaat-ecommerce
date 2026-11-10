<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\IdempotencyConflictException;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class JournalPostingService
{
    public function __construct(
        private readonly AccountResolverService $accountResolver,
    ) {}

    /**
     * Post a balanced journal entry atomically to the general ledger.
     *
     * @throws ClosedPeriodException
     * @throws UnbalancedJournalException
     * @throws InactiveAccountException
     */
    public function post(JournalEntryData $data): AccountingJournalEntry
    {
        // 1. Validate data balance and line constraints
        $data->validate();

        // 2. Check idempotency before acquiring transaction locks
        if ($data->idempotencyKey !== '') {
            $existing = AccountingJournalEntry::where('idempotency_key', $data->idempotencyKey)
                ->with('lines')
                ->first();

            if ($existing) {
                return $this->replayOf($data, $existing);
            }
        }

        // 3. Resolve active accounting period
        $period = $this->resolveActivePeriod($data->entryDate);

        // 4. Pre-resolve account IDs via AccountResolverService (validates accounts are active)
        $resolvedLines = [];
        foreach ($data->lines as $line) {
            $accountId = $line->accountId;
            if ($accountId === null && $line->accountSlug !== null) {
                $account = $this->accountResolver->resolveBySlug($line->accountSlug);
                $accountId = $account->id;
            } elseif ($accountId !== null) {
                $account = $this->accountResolver->resolveById($accountId);
                $accountId = $account->id;
            } else {
                throw new DomainException('A journal line must specify either accountSlug or accountId.');
            }

            $resolvedLines[] = [
                'account_id' => $accountId,
                'debit' => round($line->debit, 2),
                'credit' => round($line->credit, 2),
                'seller_id' => $line->sellerId,
                'customer_id' => $line->customerId,
                'sub_order_id' => $line->subOrderId,
                'order_id' => $line->orderId,
                'narration' => $line->narration,
            ];
        }

        // 5. Atomic insertion wrapped in DB::transaction()
        try {
            return DB::transaction(function () use ($data, $period, $resolvedLines): AccountingJournalEntry {
                if ($data->idempotencyKey !== '') {
                    $existing = AccountingJournalEntry::where('idempotency_key', $data->idempotencyKey)
                        ->with('lines')
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $this->replayOf($data, $existing);
                    }
                }

                // Review R-8: the month may have been closed since it was checked above. A shared lock makes a
                // close wait for this posting, and this posting see a close that committed first.
                $currentPeriod = AccountingPeriod::whereKey($period->id)->sharedLock()->first();
                if ($currentPeriod?->is_closed) {
                    throw ClosedPeriodException::forPeriod($currentPeriod->name);
                }

                $yearMonth = Carbon::parse($data->entryDate)->format('Ym');
                $prefix = sprintf('JRN-%s-', $yearMonth);

                $journalNumber = sprintf('%s%06d', $prefix, $this->nextSequence($prefix));

                // Entry is created as DRAFT so journal lines can be appended without triggering immutability guards
                $entry = AccountingJournalEntry::create([
                    'journal_number' => $journalNumber,
                    'period_id' => $period->id,
                    'entry_date' => $data->entryDate,
                    'posting_date' => now(),
                    'status' => JournalEntryStatus::DRAFT,
                    'financial_event' => $data->financialEvent,
                    'reference_type' => $data->referenceType,
                    'reference_id' => $data->referenceId,
                    'idempotency_key' => $data->idempotencyKey,
                    'description' => $data->description,
                    'created_by_user_id' => $data->createdByUserId,
                    'metadata' => $data->metadata,
                ]);

                foreach ($resolvedLines as $lineAttributes) {
                    $lineAttributes['journal_entry_id'] = $entry->id;
                    AccountingJournalLine::create($lineAttributes);
                }

                // Finalize entry to POSTED status (locks entry and lines permanently)
                $entry->update([
                    'status' => JournalEntryStatus::POSTED,
                ]);

                return $entry->load('lines');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($data->idempotencyKey !== '') {
                $existing = AccountingJournalEntry::where('idempotency_key', $data->idempotencyKey)
                    ->with('lines')
                    ->first();

                if ($existing) {
                    return $this->replayOf($data, $existing);
                }
            }

            throw $e;
        }
    }

    /**
     * ACC-21: a repeat under an existing key is harmless only when it posts the same accounts and amounts (in any
     * order). Anything else is a different event that was given the same key, and is refused.
     *
     * @throws IdempotencyConflictException
     */
    private function replayOf(JournalEntryData $data, AccountingJournalEntry $existing): AccountingJournalEntry
    {
        $requested = array_map(function (JournalLineData $line): string {
            $accountId = $line->accountId ?? AccountingAccount::where('system_slug', trim((string) $line->accountSlug))->value('id');

            return $accountId.'|'.JournalEntryData::toPoisha($line->debit).'|'.JournalEntryData::toPoisha($line->credit);
        }, $data->lines);

        $posted = $existing->lines
            ->map(fn ($line): string => $line->account_id.'|'.JournalEntryData::toPoisha((float) $line->debit).'|'.JournalEntryData::toPoisha((float) $line->credit))
            ->all();

        sort($requested);
        sort($posted);

        if ($requested !== $posted) {
            throw IdempotencyConflictException::forKey($data->idempotencyKey, (string) $existing->journal_number);
        }

        return $existing;
    }

    /**
     * ACC-13: take the next number for the prefix from its counter row. The row stays locked until the caller's
     * transaction commits, so a concurrent posting waits and then sees the new value instead of reusing it.
     * A missing row is created from the highest existing number (journals posted before the counter existed).
     */
    private function nextSequence(string $prefix): int
    {
        $sequences = DB::table('accounting_journal_sequences');

        if (! (clone $sequences)->where('prefix', $prefix)->exists()) {
            $highest = AccountingJournalEntry::where('journal_number', 'like', "{$prefix}%")
                ->pluck('journal_number')
                ->map(fn (string $number): int => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;

            (clone $sequences)->insertOrIgnore(['prefix' => $prefix, 'last_number' => $highest]);
        }

        $last = (int) (clone $sequences)->where('prefix', $prefix)->lockForUpdate()->value('last_number');
        (clone $sequences)->where('prefix', $prefix)->update(['last_number' => $last + 1]);

        return $last + 1;
    }

    /**
     * Reverse a posted journal entry by creating an inverse balancing entry.
     */
    public function reverse(AccountingJournalEntry $entry, string $reason, ?User $actor = null): AccountingJournalEntry
    {
        $status = $entry->status instanceof JournalEntryStatus
            ? $entry->status
            : JournalEntryStatus::tryFrom((string) $entry->status);

        if ($status === JournalEntryStatus::REVERSED) {
            return $entry->reversalJournal ?? AccountingJournalEntry::findOrFail($entry->reversal_journal_id);
        }

        if ($status !== JournalEntryStatus::POSTED) {
            throw new DomainException("Cannot reverse journal entry with status '{$status?->value}'. Only posted entries can be reversed.");
        }

        // Determine posting date for reversal (either current active period or original date if still open)
        $originalDate = $entry->entry_date instanceof \DateTimeInterface
            ? $entry->entry_date->format('Y-m-d')
            : (string) $entry->entry_date;

        $originalPeriod = AccountingPeriod::where('start_date', '<=', $originalDate)
            ->where('end_date', '>=', $originalDate)
            ->first();

        if ($originalPeriod && ! $originalPeriod->is_closed) {
            $reversalDate = $originalDate;
        } else {
            $today = now()->format('Y-m-d');
            $todayPeriod = AccountingPeriod::where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->first();

            if ($todayPeriod && ! $todayPeriod->is_closed) {
                $reversalDate = $today;
            } else {
                $activePeriod = AccountingPeriod::where('is_closed', false)
                    ->orderByDesc('start_date')
                    ->first();
                $reversalDate = $activePeriod?->start_date?->format('Y-m-d') ?? $today;
            }
        }

        $entry->loadMissing('lines.account');

        $reversalLines = [];
        foreach ($entry->lines as $line) {
            $reversalLines[] = new JournalLineData(
                accountSlug: $line->account?->system_slug,
                accountId: $line->account_id,
                debit: (float) $line->credit,
                credit: (float) $line->debit,
                sellerId: $line->seller_id,
                customerId: $line->customer_id,
                subOrderId: $line->sub_order_id,
                orderId: $line->order_id,
                narration: 'Reversal: '.($line->narration ?? $entry->description),
            );
        }

        $reversalData = new JournalEntryData(
            entryDate: $reversalDate,
            financialEvent: 'reversal',
            idempotencyKey: "reversal_{$entry->id}_{$entry->journal_number}",
            description: "Reversal of {$entry->journal_number}: {$reason}",
            lines: $reversalLines,
            referenceType: AccountingJournalEntry::class,
            referenceId: $entry->id,
            createdByUserId: $actor?->id,
            metadata: [
                'reversed_journal_id' => $entry->id,
                'reversed_journal_number' => $entry->journal_number,
                'reversal_reason' => $reason,
            ],
        );

        return DB::transaction(function () use ($entry, $reversalData): AccountingJournalEntry {
            $reversalEntry = $this->post($reversalData);

            $entry->update([
                'status' => JournalEntryStatus::REVERSED,
                'reversal_journal_id' => $reversalEntry->id,
            ]);

            return $reversalEntry;
        });
    }

    /**
     * Resolve active accounting period for an entry date.
     * Throws ClosedPeriodException only if a period exists and is explicitly closed.
     * Auto-provisions an active open period if none is defined for the date.
     *
     * @throws ClosedPeriodException
     */
    public function resolveActivePeriod(string $entryDate): AccountingPeriod
    {
        $period = AccountingPeriod::where('start_date', '<=', $entryDate)
            ->where('end_date', '>=', $entryDate)
            ->first();

        if ($period) {
            if ($period->is_closed) {
                throw ClosedPeriodException::forPeriod($period->name);
            }

            return $period;
        }

        return $this->autoProvisionPeriod($entryDate);
    }

    protected function autoProvisionPeriod(string $entryDate): AccountingPeriod
    {
        $carbon = Carbon::parse($entryDate);
        $month = (int) $carbon->month;
        $year = (int) $carbon->year;

        if ($month >= 7) {
            $startYear = $year;
            $endYear = $year + 1;
            $periodNumber = $month - 6;
        } else {
            $startYear = $year - 1;
            $endYear = $year;
            $periodNumber = $month + 6;
        }

        $fiscalYear = AccountingFiscalYear::firstOrCreate(
            ['name' => "FY {$startYear}-{$endYear}"],
            [
                'start_date' => "{$startYear}-07-01",
                'end_date' => "{$endYear}-06-30",
                'is_closed' => false,
            ]
        );

        return AccountingPeriod::firstOrCreate(
            [
                'fiscal_year_id' => $fiscalYear->id,
                'start_date' => $carbon->copy()->startOfMonth()->toDateString(),
                'end_date' => $carbon->copy()->endOfMonth()->toDateString(),
            ],
            [
                'period_number' => $periodNumber,
                'name' => $carbon->format('F Y'),
                'is_closed' => false,
            ]
        );
    }
}
