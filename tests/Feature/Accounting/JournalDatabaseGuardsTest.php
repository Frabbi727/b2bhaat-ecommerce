<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACC-9: the database itself protects the books, so a direct query can't remove, change or unbalance posted journals.
 * The foreign-key rule runs on every database; the PostgreSQL-only tests run when the suite is pointed at PostgreSQL.
 */
class JournalDatabaseGuardsTest extends TestCase
{
    use RefreshDatabase;

    private AccountingJournalEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->entry = app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: now()->format('Y-m-d'),
            financialEvent: 'test_event',
            idempotencyKey: 'acc9-guard-test',
            description: 'Guard test entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 100.00),
                new JournalLineData(accountSlug: 'customer_refund_payable', credit: 100.00),
            ],
        ));
    }

    public function test_an_entry_with_lines_cannot_be_deleted_by_a_direct_query(): void
    {
        $this->expectException(QueryException::class);

        DB::table('accounting_journal_entries')->where('id', $this->entry->id)->delete();
    }

    public function test_posted_lines_cannot_be_deleted_by_a_direct_query(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('accounting_journal_lines')->where('journal_entry_id', $this->entry->id)->delete();
    }

    public function test_posted_line_amounts_cannot_be_changed_by_a_direct_query(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('accounting_journal_lines')->where('journal_entry_id', $this->entry->id)->update(['debit' => 999]);
    }

    public function test_a_posted_entry_cannot_be_edited_by_a_direct_query(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('accounting_journal_entries')->where('id', $this->entry->id)->update(['description' => 'Edited']);
    }

    public function test_a_line_cannot_be_added_to_a_posted_entry(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        DB::table('accounting_journal_lines')->insert([
            'journal_entry_id' => $this->entry->id,
            'account_id' => AccountingAccount::where('system_slug', 'cash_on_hand')->value('id'),
            'debit' => 50,
            'credit' => 0,
        ]);
    }

    public function test_a_negative_line_is_refused(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        $this->insertDraftLine(debit: -10, credit: 0);
    }

    public function test_a_line_with_both_sides_is_refused(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        $this->insertDraftLine(debit: 10, credit: 10);
    }

    public function test_an_unbalanced_draft_cannot_be_posted(): void
    {
        $this->requirePostgres();
        $draftId = $this->insertDraftLine(debit: 10, credit: 0);

        $this->expectException(QueryException::class);

        DB::table('accounting_journal_entries')->where('id', $draftId)->update(['status' => 'posted']);
    }

    public function test_reversal_still_works(): void
    {
        $reversal = app(JournalPostingService::class)->reverse($this->entry, 'Guard test');

        $this->assertSame('reversed', $this->entry->fresh()->status->value);
        $this->assertSame($reversal->id, $this->entry->fresh()->reversal_journal_id);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This database guard is enforced by PostgreSQL triggers and constraints.');
        }
    }

    /**
     * Creates a draft entry with one line and returns the entry id.
     */
    private function insertDraftLine(float $debit, float $credit): int
    {
        $draftId = DB::table('accounting_journal_entries')->insertGetId([
            'journal_number' => 'JRN-TEST-'.uniqid(),
            'period_id' => $this->entry->period_id,
            'entry_date' => now()->format('Y-m-d'),
            'posting_date' => now(),
            'status' => 'draft',
            'financial_event' => 'test_event',
            'idempotency_key' => 'acc9-draft-'.uniqid(),
            'description' => 'Draft',
        ]);

        DB::table('accounting_journal_lines')->insert([
            'journal_entry_id' => $draftId,
            'account_id' => AccountingAccount::where('system_slug', 'cash_on_hand')->value('id'),
            'debit' => $debit,
            'credit' => $credit,
        ]);

        return $draftId;
    }
}
