<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Filament\Seller\Resources\WalletTransactions\Widgets\SellerStatementOverviewWidget;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SellerLedgerStatementService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACC-19: a seller's statement totals come from the ledger. The old totals counted rejected and still-waiting
 * payouts as withdrawn and ignored refunds and fees taken from the balance.
 */
class SellerLedgerStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->seller = $this->makeSeller('Ledger Store');
    }

    private function makeSeller(string $storeName): User
    {
        $seller = User::factory()->create([
            'role_id' => Role::where('slug', 'seller')->firstOrFail()->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => $storeName,
            'store_slug' => str($storeName)->slug().'-'.$seller->id,
            'is_approved' => true,
        ]);

        return $seller;
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function postEntry(string $event, array $lines): AccountingJournalEntry
    {
        return app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: $event,
            idempotencyKey: 'acc19-'.(++$this->sequence),
            description: 'Test entry',
            lines: $lines,
        ));
    }

    private function payout(int $sellerId, float $amount, string $outcome): ?AccountingJournalEntry
    {
        $this->postEntry('payout_requested', [
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: $amount, sellerId: $sellerId),
            new JournalLineData(accountSlug: 'reserved_payout_payable', credit: $amount, sellerId: $sellerId),
        ]);

        return match ($outcome) {
            'disbursed' => $this->postEntry('payout_disbursed', [
                new JournalLineData(accountSlug: 'reserved_payout_payable', debit: $amount, sellerId: $sellerId),
                new JournalLineData(accountSlug: 'bank_operating', credit: $amount),
            ]),
            'rejected' => $this->postEntry('payout_rejected', [
                new JournalLineData(accountSlug: 'reserved_payout_payable', debit: $amount, sellerId: $sellerId),
                new JournalLineData(accountSlug: 'seller_wallet_payable', credit: $amount, sellerId: $sellerId),
            ]),
            default => null,
        };
    }

    /**
     * Release 10,000 with 2,300 still in escrow; payouts of 3,000 and 1,500 paid, 1,000 waiting, 500 rejected; a
     * 499.50 subscription paid from the balance; 4,000.50 left available.
     */
    private function seedLedger(): ?AccountingJournalEntry
    {
        $id = $this->seller->id;

        $this->postEntry('order_prepaid_received', [
            new JournalLineData(accountSlug: 'gateway_clearing', debit: 12300.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 12300.00, sellerId: $id),
        ]);
        $this->postEntry('escrow_released', [
            new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 10000.00, sellerId: $id),
            new JournalLineData(accountSlug: 'seller_wallet_payable', credit: 10000.00, sellerId: $id),
        ]);
        $this->payout($id, 3000.00, 'disbursed');
        $second = $this->payout($id, 1500.00, 'disbursed');
        $this->payout($id, 1000.00, 'waiting');
        $this->payout($id, 500.00, 'rejected');
        $this->postEntry('subscription_paid', [
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 499.50, sellerId: $id),
            new JournalLineData(accountSlug: 'subscription_revenue', credit: 499.50),
        ]);

        // Another seller's money never shows up
        $other = $this->makeSeller('Other Store');
        $this->postEntry('order_prepaid_received', [
            new JournalLineData(accountSlug: 'gateway_clearing', debit: 77777.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 77777.00, sellerId: $other->id),
        ]);

        return $second;
    }

    public function test_totals_come_from_the_ledger_and_tie_together(): void
    {
        $this->seedLedger();

        $summary = app(SellerLedgerStatementService::class)->summary($this->seller->id);

        $this->assertSame(4000.5, $summary['available_balance']);
        $this->assertSame(2300.0, $summary['escrow_held']);
        $this->assertSame(1000.0, $summary['pending_payouts']);
        $this->assertSame(10000.0, $summary['net_earnings']);
        $this->assertSame(499.5, $summary['fees_paid_from_balance']);
        $this->assertSame(4500.0, $summary['total_withdrawn']);
        $this->assertTrue($summary['is_consistent']);
    }

    public function test_a_reversed_disbursement_is_no_longer_counted_as_withdrawn(): void
    {
        $second = $this->seedLedger();
        app(JournalPostingService::class)->reverse($second, 'Bank returned the transfer');

        $summary = app(SellerLedgerStatementService::class)->summary($this->seller->id);

        $this->assertSame(3000.0, $summary['total_withdrawn']);
        $this->assertSame(2500.0, $summary['pending_payouts']);
        $this->assertSame(4000.5, $summary['available_balance']);
        $this->assertTrue($summary['is_consistent']);
    }

    public function test_a_cod_deficit_payment_counts_as_paid_in_not_earnings(): void
    {
        $id = $this->seller->id;
        $this->postEntry('cod_self_delivered', [
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 150.00, sellerId: $id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 150.00, sellerId: $id),
        ]);
        $this->postEntry('cod_deficit_paid', [
            new JournalLineData(accountSlug: 'gateway_clearing', debit: 150.00),
            new JournalLineData(accountSlug: 'seller_wallet_payable', credit: 150.00, sellerId: $id),
        ]);

        $summary = app(SellerLedgerStatementService::class)->summary($id);

        $this->assertSame(0.0, $summary['available_balance']);
        $this->assertSame(150.0, $summary['paid_in']);
        $this->assertSame(-150.0, $summary['net_earnings']);
        $this->assertTrue($summary['is_consistent']);
    }

    /**
     * The wallet table says something else; the statement follows the ledger.
     */
    private function walletRowsThatDisagree(): void
    {
        $wallet = SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 4000.50,
            'escrow_held_balance' => 2300.00,
        ]);
        foreach ([[WalletTransaction::STATUS_DISBURSED, 4500.00], [WalletTransaction::STATUS_REJECTED, 500.00], [WalletTransaction::STATUS_REQUESTED, 1000.00]] as [$status, $amount]) {
            WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'transaction_type' => WalletTransaction::TYPE_PAYOUT,
                'status' => $status,
                'amount' => $amount,
                'running_balance' => 0,
            ]);
        }
    }

    public function test_the_seller_widget_shows_ledger_totals(): void
    {
        $this->seedLedger();
        $this->walletRowsThatDisagree();

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SellerStatementOverviewWidget::class)
            ->assertSee('৳4,000.50')
            ->assertSee('৳2,300.00')
            ->assertSee('৳10,000.00')
            ->assertSee('৳4,500.00')
            ->assertSee('৳1,000.00 waiting to be paid')
            ->assertDontSee('৳77,777.00');
    }

    public function test_the_printed_statement_and_csv_use_ledger_totals(): void
    {
        $this->seedLedger();
        $this->walletRowsThatDisagree();
        $this->actingAs($this->seller);

        $this->get(route('seller.wallet.statement.print'))
            ->assertOk()
            ->assertSee('৳10,000.00')
            ->assertSee('৳4,500.00')
            ->assertDontSee('৳6,000.00')
            ->assertSee('৳2,300.00')
            ->assertSee('৳4,000.50');

        $csv = $this->get(route('seller.wallet.statement.csv'))->streamedContent();
        $this->assertStringContainsString('"Total Withdrawn (BDT):",4500.00', $csv);
        $this->assertStringContainsString('"Payouts Waiting (BDT):",1000.00', $csv);
    }
}
