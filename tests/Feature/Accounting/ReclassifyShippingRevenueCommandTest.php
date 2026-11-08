<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-1: historical order journals booked the courier shipping fee as a credit to an expense account.
 * The correcting command moves it to shipping revenue with new entries and never edits posted ones.
 */
class ReclassifyShippingRevenueCommandTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $posting;

    private GeneralLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $year = AccountingFiscalYear::create(['name' => 'FY 2026-2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 5, 'name' => 'November 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);

        $this->posting = app(JournalPostingService::class);
        $this->ledger = app(GeneralLedgerService::class);
    }

    private function oldStylePrepaidJournal(string $key, float $shipping): AccountingJournalEntry
    {
        return $this->posting->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'order_prepaid_received',
            idempotencyKey: $key,
            description: 'Historical prepaid order',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 1000 + $shipping),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900),
                new JournalLineData(accountSlug: 'commission_revenue', credit: 100),
                new JournalLineData(accountSlug: 'courier_delivery_expense', credit: $shipping),
            ],
        ));
    }

    public function test_dry_run_posts_nothing(): void
    {
        $this->oldStylePrepaidJournal('hist_1', 80);
        $before = AccountingJournalEntry::count();

        $this->artisan('accounting:reclassify-shipping', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, AccountingJournalEntry::count());
        $this->assertSame(-80.0, $this->ledger->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_moves_shipping_to_revenue_and_leaves_real_courier_bills_alone(): void
    {
        $original = $this->oldStylePrepaidJournal('hist_1', 80);
        $reversed = $this->oldStylePrepaidJournal('hist_2', 60);
        $this->posting->reverse($reversed, 'Order cancelled');
        $this->posting->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'manual_journal',
            idempotencyKey: 'real_courier_bill',
            description: 'Courier invoice paid',
            lines: [
                new JournalLineData(accountSlug: 'courier_delivery_expense', debit: 300),
                new JournalLineData(accountSlug: 'cash_on_hand', credit: 300),
            ],
        ));
        $originalLines = AccountingJournalLine::where('journal_entry_id', $original->id)->get()->toArray();

        $this->artisan('accounting:reclassify-shipping')->assertSuccessful();

        $this->assertSame(80.0, $this->ledger->getBalanceForSlug('shipping_service_revenue'));
        $this->assertSame(300.0, $this->ledger->getBalanceForSlug('courier_delivery_expense'), 'only the real courier bill stays as expense');
        $this->assertSame($originalLines, AccountingJournalLine::where('journal_entry_id', $original->id)->get()->toArray(), 'posted journals are never edited');
        $this->assertSame(3, AccountingJournalEntry::where('financial_event', 'shipping_reclassified')->count());

        $debits = (float) AccountingJournalLine::sum('debit');
        $credits = (float) AccountingJournalLine::sum('credit');
        $this->assertSame(round($debits, 2), round($credits, 2), 'trial balance stays balanced');
    }

    public function test_running_twice_posts_the_correction_once(): void
    {
        $this->oldStylePrepaidJournal('hist_1', 80);

        $this->artisan('accounting:reclassify-shipping')->assertSuccessful();
        $this->artisan('accounting:reclassify-shipping')->assertSuccessful();

        $this->assertSame(1, AccountingJournalEntry::where('financial_event', 'shipping_reclassified')->count());
        $this->assertSame(80.0, $this->ledger->getBalanceForSlug('shipping_service_revenue'));
    }
}
