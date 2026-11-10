<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\Accounting\AccountingJournalLine;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-15: balance is checked on the amounts as they are stored (whole poisha per line), so a journal that only
 * balances before rounding is refused instead of being saved unbalanced.
 */
class JournalRoundingTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $lines): JournalEntryData
    {
        return new JournalEntryData(
            entryDate: '2026-11-10',
            financialEvent: 'manual_adjustment',
            idempotencyKey: 'rounding-'.md5(serialize($lines)),
            description: 'Rounding',
            lines: $lines,
        );
    }

    public function test_a_journal_that_only_balances_before_rounding_is_refused(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->expectException(UnbalancedJournalException::class);

        app(JournalPostingService::class)->post($this->entry([
            new JournalLineData(accountSlug: 'bank_operating', debit: 10.006),
            new JournalLineData(accountSlug: 'owner_equity', credit: 5.003),
            new JournalLineData(accountSlug: 'owner_equity', credit: 5.003),
        ]));
    }

    public function test_totals_are_the_sum_of_the_stored_line_amounts(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $data = $this->entry([
            new JournalLineData(accountSlug: 'bank_operating', debit: 0.1),
            new JournalLineData(accountSlug: 'bank_operating', debit: 0.2),
            new JournalLineData(accountSlug: 'owner_equity', credit: 0.3),
        ]);
        $this->assertSame(0.3, $data->totalDebit());

        $entry = app(JournalPostingService::class)->post($data);
        $this->assertSame('0.30', number_format((float) AccountingJournalLine::where('journal_entry_id', $entry->id)->sum('debit'), 2));
    }
}
