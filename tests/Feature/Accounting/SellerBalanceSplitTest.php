<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SellerBalanceService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1 (finance audit 2026-11-20): what sellers owe the platform is never netted against what the platform owes
 * other sellers. Each seller's three accounts are netted first; then the sellers in credit and the sellers in
 * debit are added up separately.
 */
class SellerBalanceSplitTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private User $sellerInCredit;

    private User $sellerInDebit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->sellerInCredit = User::factory()->create();
        $this->sellerInDebit = User::factory()->create();

        // Seller A: ৳900 delivered by courier, then released to their wallet
        $this->postEntry('2026-11-03', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 1000.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00, sellerId: $this->sellerInCredit->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 100.00),
        ]);
        $this->postEntry('2026-11-05', [
            new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 900.00, sellerId: $this->sellerInCredit->id),
            new JournalLineData(accountSlug: 'seller_wallet_payable', credit: 900.00, sellerId: $this->sellerInCredit->id),
        ]);

        // Seller B: kept a buyer's cash, so owes ৳150 commission
        $this->postEntry('2026-11-06', [
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 150.00, sellerId: $this->sellerInDebit->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 150.00),
        ]);
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function postEntry(string $date, array $lines): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: $date,
            financialEvent: 'manual_journal',
            idempotencyKey: 'f1-'.(++$this->sequence),
            description: 'Test entry',
            lines: $lines,
        ));
    }

    public function test_seller_debts_and_seller_money_are_added_up_separately(): void
    {
        $split = app(SellerBalanceService::class)->split();

        $this->assertSame(900.0, $split['owed_to_sellers']);
        $this->assertSame(150.0, $split['owed_by_sellers']);
        $this->assertSame(1, $split['sellers_owed']);
        $this->assertSame(1, $split['sellers_owing']);
        $this->assertSame(750.0, $split['net']);
    }

    public function test_a_sellers_debt_is_netted_against_their_own_money_first(): void
    {
        // Seller B now has ৳400 on hold: they owe nothing once their own ৳150 debt is taken off
        $this->postEntry('2026-11-07', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 400.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 400.00, sellerId: $this->sellerInDebit->id),
        ]);

        $split = app(SellerBalanceService::class)->split();

        $this->assertSame(1150.0, $split['owed_to_sellers']);
        $this->assertSame(0.0, $split['owed_by_sellers']);
        $this->assertSame(['escrow' => 400.0, 'wallet' => -150.0, 'reserved' => 0.0, 'net' => 250.0], app(SellerBalanceService::class)->balancesBySeller()[$this->sellerInDebit->id]);
    }

    public function test_the_split_respects_the_as_of_date(): void
    {
        $split = app(SellerBalanceService::class)->split('2026-11-05');

        $this->assertSame(900.0, $split['owed_to_sellers']);
        $this->assertSame(0.0, $split['owed_by_sellers']);
    }

    public function test_the_balance_sheet_shows_seller_debts_as_an_asset_and_still_balances(): void
    {
        $sheet = app(FinancialStatementService::class)->getBalanceSheet('2026-11-30');

        $receivable = collect($sheet['assets']['accounts'])->firstWhere('code', '1050');
        $wallet = collect($sheet['liabilities']['accounts'])->firstWhere('code', '2020');

        $this->assertSame(150.0, $receivable['amount']);
        $this->assertSame(900.0, $wallet['amount']);
        $this->assertNotEmpty($receivable['note']);
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(1150.0, $sheet['assets']['total']);
    }
}
