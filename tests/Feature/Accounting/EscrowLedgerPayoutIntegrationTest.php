<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowLedgerPayoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerWallet $wallet;

    protected EscrowLedgerService $escrowLedger;

    protected GeneralLedgerService $gl;

    protected JournalPostingService $journalPostingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY 2026-2027'],
            ['start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'is_closed' => false]
        );

        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n')],
            [
                'name' => now()->format('F Y'),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'is_closed' => false,
            ]
        );

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => 'Factory Seller A',
            'is_active' => true,
        ]);

        $this->wallet = SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 0.00,
        ]);

        $this->escrowLedger = app(EscrowLedgerService::class);
        $this->gl = app(GeneralLedgerService::class);
        $this->journalPostingService = app(JournalPostingService::class);
    }

    /**
     * Helper to post an initial available wallet balance in GL so GL matches $this->wallet->available_balance.
     */
    protected function seedInitialWalletPayableGl(float $amount): void
    {
        $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: "initial_wallet_seed_{$this->wallet->id}",
            description: "Initial wallet balance seed for seller #{$this->seller->id}",
            lines: [
                new JournalLineData(
                    accountSlug: 'seller_escrow_payable',
                    debit: $amount,
                    credit: 0.00,
                    sellerId: $this->seller->id,
                    narration: 'Release escrow to available wallet',
                ),
                new JournalLineData(
                    accountSlug: 'seller_wallet_payable',
                    debit: 0.00,
                    credit: $amount,
                    sellerId: $this->seller->id,
                    narration: 'Initial seller wallet balance',
                ),
            ],
            referenceType: SellerWallet::class,
            referenceId: $this->wallet->id,
        ));
    }

    /**
     * Test 1: Calling requestPayout() decrements wallet available balance AND generates
     * a posted payout_requested journal (Dr seller_wallet_payable / Cr reserved_payout_payable scoped to seller).
     */
    public function test_request_payout_decrements_wallet_and_generates_reservation_journal(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 1500.00, 'Withdrawal to Bank');

        // 1. Check wallet transaction and available balance
        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $payout->status);
        $this->assertEquals(1500.00, (float) $payout->amount);
        $this->assertEquals(3500.00, (float) $this->wallet->fresh()->available_balance);

        // 2. Verify journal entry exists and is posted
        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_REQUESTED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry, 'payout_requested journal entry should be created');
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertEquals(1500.00, (float) $entry->lines()->sum('debit'));
        $this->assertEquals(1500.00, (float) $entry->lines()->sum('credit'));

        // 3. Verify lines: Dr seller_wallet_payable / Cr reserved_payout_payable scoped to seller
        $debitLine = $entry->lines()->where('debit', '>', 0)->first();
        $creditLine = $entry->lines()->where('credit', '>', 0)->first();

        $this->assertNotNull($debitLine);
        $this->assertSame('seller_wallet_payable', $debitLine->account->system_slug);
        $this->assertEquals(1500.00, (float) $debitLine->debit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        $this->assertNotNull($creditLine);
        $this->assertSame('reserved_payout_payable', $creditLine->account->system_slug);
        $this->assertEquals(1500.00, (float) $creditLine->credit);
        $this->assertSame($this->seller->id, $creditLine->seller_id);
    }

    /**
     * Test 2: Calling disbursePayout() with bank channel generates a posted payout_disbursed journal
     * (Dr reserved_payout_payable / Cr bank_operating).
     */
    public function test_disburse_payout_with_bank_channel_generates_disbursement_journal(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 2000.00);

        $this->escrowLedger->disbursePayout($payout, 'EFT-998822', 'bank');

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('EFT-998822', (string) $payout->reference_note);

        // Verify payout_disbursed journal
        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry, 'payout_disbursed journal entry should be created');
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertEquals(2000.00, (float) $entry->lines()->sum('debit'));
        $this->assertEquals(2000.00, (float) $entry->lines()->sum('credit'));

        // Dr reserved_payout_payable / Cr bank_operating
        $debitLine = $entry->lines()->where('debit', '>', 0)->first();
        $creditLine = $entry->lines()->where('credit', '>', 0)->first();

        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertEquals(2000.00, (float) $debitLine->debit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        $this->assertNotNull($creditLine);
        $this->assertSame('bank_operating', $creditLine->account->system_slug);
        $this->assertEquals(2000.00, (float) $creditLine->credit);
        $this->assertNull($creditLine->seller_id);
    }

    /**
     * Test 3: Calling disbursePayout() with MFS channel (e.g. 'bkash') generates a posted payout_disbursed journal
     * (Dr reserved_payout_payable / Cr gateway_clearing).
     */
    public function test_disburse_payout_with_mfs_channel_credits_gateway_clearing(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 1000.00);

        $this->escrowLedger->disbursePayout($payout, 'BKASH-TRX-5544', 'bkash');

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('BKASH-TRX-5544', (string) $payout->reference_note);

        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);

        $debitLine = $entry->lines()->where('debit', '>', 0)->first();
        $creditLine = $entry->lines()->where('credit', '>', 0)->first();

        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertEquals(1000.00, (float) $debitLine->debit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        $this->assertNotNull($creditLine);
        $this->assertSame('gateway_clearing', $creditLine->account->system_slug);
        $this->assertEquals(1000.00, (float) $creditLine->credit);
        $this->assertNull($creditLine->seller_id);
    }

    /**
     * Test 4: Calling rejectPayout() restores wallet available balance AND generates a posted payout_rejected journal
     * (Dr reserved_payout_payable / Cr seller_wallet_payable scoped to seller).
     */
    public function test_reject_payout_restores_wallet_and_generates_reversal_journal(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 1200.00);
        $this->assertEquals(3800.00, (float) $this->wallet->fresh()->available_balance);

        $reversalTx = $this->escrowLedger->rejectPayout($payout, 'Invalid routing number');

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_REJECTED, $payout->status);
        $this->assertStringContainsString('Invalid routing number', (string) $payout->reference_note);

        // Reversal wallet transaction created and wallet balance restored
        $this->assertInstanceOf(WalletTransaction::class, $reversalTx);
        $this->assertSame(WalletTransaction::TYPE_PAYOUT_REVERSAL, $reversalTx->transaction_type);
        $this->assertEquals(1200.00, (float) $reversalTx->amount);
        $this->assertEquals(5000.00, (float) $this->wallet->fresh()->available_balance);

        // Verify payout_rejected journal entry
        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_REJECTED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry, 'payout_rejected journal entry should be created');
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertEquals(1200.00, (float) $entry->lines()->sum('debit'));
        $this->assertEquals(1200.00, (float) $entry->lines()->sum('credit'));

        // Dr reserved_payout_payable / Cr seller_wallet_payable scoped to seller
        $debitLine = $entry->lines()->where('debit', '>', 0)->first();
        $creditLine = $entry->lines()->where('credit', '>', 0)->first();

        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertEquals(1200.00, (float) $debitLine->debit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        $this->assertNotNull($creditLine);
        $this->assertSame('seller_wallet_payable', $creditLine->account->system_slug);
        $this->assertEquals(1200.00, (float) $creditLine->credit);
        $this->assertSame($this->seller->id, $creditLine->seller_id);
    }

    /**
     * Test 5: Reconciles seller wallet and reserved payout payable after each transition.
     */
    public function test_reconciles_seller_wallet_and_reserved_payout_after_each_transition(): void
    {
        // 0. Initial state: Seed GL with 5,000 to match wallet available balance
        $this->seedInitialWalletPayableGl(5000.00);

        $glSellerWallet = $this->gl->getBalanceForSlug('seller_wallet_payable', $this->seller->id);
        $glReservedPayout = $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id);

        $this->assertEquals(5000.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(5000.00, $glSellerWallet);
        $this->assertEquals(0.00, $glReservedPayout);

        // 1. Transition: requestPayout(2000.00)
        $payout1 = $this->escrowLedger->requestPayout($this->seller, 2000.00, 'First withdrawal');

        $this->assertEquals(3000.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(3000.00, $this->gl->getBalanceForSlug('seller_wallet_payable', $this->seller->id));
        $this->assertEquals(2000.00, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        // Total pending payouts in DB matches reserved payout payable GL
        $pendingPayoutTotal = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->sum('amount');
        $this->assertEquals($pendingPayoutTotal, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        // 2. Transition: disbursePayout(payout1)
        $this->escrowLedger->disbursePayout($payout1, 'BANK-DISBURSE-101', 'bank');

        $this->assertEquals(3000.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(3000.00, $this->gl->getBalanceForSlug('seller_wallet_payable', $this->seller->id));
        $this->assertEquals(0.00, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        $pendingPayoutTotal = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->sum('amount');
        $this->assertEquals(0.00, $pendingPayoutTotal);
        $this->assertEquals($pendingPayoutTotal, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        // 3. Transition: requestPayout(1200.00)
        $payout2 = $this->escrowLedger->requestPayout($this->seller, 1200.00, 'Second withdrawal');

        $this->assertEquals(1800.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(1800.00, $this->gl->getBalanceForSlug('seller_wallet_payable', $this->seller->id));
        $this->assertEquals(1200.00, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        $pendingPayoutTotal = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->sum('amount');
        $this->assertEquals(1200.00, $pendingPayoutTotal);
        $this->assertEquals($pendingPayoutTotal, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        // 4. Transition: rejectPayout(payout2)
        $this->escrowLedger->rejectPayout($payout2, 'Invalid bank credentials');

        $this->assertEquals(3000.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(3000.00, $this->gl->getBalanceForSlug('seller_wallet_payable', $this->seller->id));
        $this->assertEquals(0.00, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));

        $pendingPayoutTotal = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->sum('amount');
        $this->assertEquals(0.00, $pendingPayoutTotal);
        $this->assertEquals($pendingPayoutTotal, $this->gl->getBalanceForSlug('reserved_payout_payable', $this->seller->id));
    }
}
