<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACC-13: journal numbers come from a locked counter row, so two postings at the same moment never pick the same
 * number (which used to fail the unique index and roll back the order, payout or refund that was being booked).
 */
class JournalNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);
    }

    private function postJournal(string $key): AccountingJournalEntry
    {
        return app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: '2026-11-09',
            financialEvent: 'manual_adjustment',
            idempotencyKey: $key,
            description: 'Test '.$key,
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 10.00),
                new JournalLineData(accountSlug: 'owner_equity', credit: 10.00),
            ],
        ));
    }

    public function test_numbers_run_in_order_per_month(): void
    {
        $this->assertSame('JRN-202611-000001', $this->postJournal('a')->journal_number);
        $this->assertSame('JRN-202611-000002', $this->postJournal('b')->journal_number);
        $this->assertSame(2, (int) DB::table('accounting_journal_sequences')->where('prefix', 'JRN-202611-')->value('last_number'));
    }

    public function test_a_number_already_handed_out_is_never_reused(): void
    {
        // Another process has taken 000005 but its entry is not visible yet (its transaction is still open).
        DB::table('accounting_journal_sequences')->insert(['prefix' => 'JRN-202611-', 'last_number' => 5]);

        $this->assertSame('JRN-202611-000006', $this->postJournal('c')->journal_number);
    }

    public function test_the_counter_starts_after_existing_journals(): void
    {
        $this->postJournal('d');
        $this->postJournal('e');
        // A database where the counter row is missing, e.g. journals posted before this change.
        DB::table('accounting_journal_sequences')->delete();

        $this->assertSame('JRN-202611-000003', $this->postJournal('f')->journal_number);
    }
}
