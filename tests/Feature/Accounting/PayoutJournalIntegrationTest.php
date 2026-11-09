<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\MissingAccountException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\EventPipeline\PayoutJournalHandler;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutJournalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerWallet $wallet;

    protected PayoutJournalHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY 2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_closed' => false]
        );

        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => 5],
            ['name' => 'November 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'is_closed' => false]
        );

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->wallet = SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 0.00,
        ]);

        $this->handler = app(PayoutJournalHandler::class);
    }

    /**
     * Test 1: Payout requested creates reservation journal: debits seller_wallet_payable (seller scoped),
     * credits reserved_payout_payable (seller scoped).
     */
    public function test_payout_requested_creates_reservation_journal(): void
    {
        $payout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 1500.00,
            'running_balance' => 3500.00,
            'reference_note' => 'Payout requested by merchant',
        ]);

        $entry = $this->handler->recordPayoutRequested($payout);

        $this->assertNotNull($entry);
        $this->assertSame(AccountingEvent::PAYOUT_REQUESTED->value, $entry->financial_event);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame("payout_requested_{$payout->id}", $entry->idempotency_key);
        $this->assertSame(WalletTransaction::class, $entry->reference_type);
        $this->assertSame($payout->id, $entry->reference_id);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);
        $this->assertEquals(1500.00, (float) $entry->lines->sum('debit'));
        $this->assertEquals(1500.00, (float) $entry->lines->sum('credit'));

        // Debit line: seller_wallet_payable (seller scoped)
        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('seller_wallet_payable', $debitLine->account->system_slug);
        $this->assertSame('1500.00', (string) $debitLine->debit);
        $this->assertSame('0.00', (string) $debitLine->credit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        // Credit line: reserved_payout_payable (seller scoped)
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('reserved_payout_payable', $creditLine->account->system_slug);
        $this->assertSame('0.00', (string) $creditLine->debit);
        $this->assertSame('1500.00', (string) $creditLine->credit);
        $this->assertSame($this->seller->id, $creditLine->seller_id);
    }

    /**
     * Test 2: Payout disbursed via bank debits reserved_payout_payable and credits bank_operating.
     */
    public function test_payout_disbursed_via_bank_debits_reserved_payout_and_credits_bank_operating(): void
    {
        $payout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'amount' => 2000.00,
            'running_balance' => 3000.00,
            'reference_note' => 'Disbursed via City Bank wire',
        ]);

        $entry = $this->handler->recordPayoutDisbursed($payout, 'bank', 'CITY-TRX-98765');

        $this->assertNotNull($entry);
        $this->assertSame(AccountingEvent::PAYOUT_DISBURSED->value, $entry->financial_event);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame("payout_disbursed_{$payout->id}", $entry->idempotency_key);
        $this->assertSame(WalletTransaction::class, $entry->reference_type);
        $this->assertSame($payout->id, $entry->reference_id);
        $this->assertStringContainsString('CITY-TRX-98765', $entry->description);
        $this->assertStringContainsString('bank', $entry->description);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);
        $this->assertEquals(2000.00, (float) $entry->lines->sum('debit'));
        $this->assertEquals(2000.00, (float) $entry->lines->sum('credit'));

        // Debit: reserved_payout_payable (seller scoped)
        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertSame('2000.00', (string) $debitLine->debit);
        $this->assertSame('0.00', (string) $debitLine->credit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        // Credit: bank_operating (general platform asset, seller_id is null)
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('bank_operating', $creditLine->account->system_slug);
        $this->assertSame('0.00', (string) $creditLine->debit);
        $this->assertSame('2000.00', (string) $creditLine->credit);
        $this->assertNull($creditLine->seller_id);
    }

    /**
     * Test 3: Payout disbursed via MFS (bkash/nagad) credits gateway_clearing.
     */
    public function test_payout_disbursed_via_mfs_credits_gateway_clearing(): void
    {
        $mfsChannels = ['bkash', 'nagad', 'sslcommerz', 'mfs'];

        foreach ($mfsChannels as $channel) {
            $payout = WalletTransaction::create([
                'seller_wallet_id' => $this->wallet->id,
                'transaction_type' => WalletTransaction::TYPE_PAYOUT,
                'status' => WalletTransaction::STATUS_DISBURSED,
                'amount' => 500.00,
                'running_balance' => 2500.00,
                'reference_note' => "Disbursed via {$channel}",
            ]);

            $entry = $this->handler->recordPayoutDisbursed($payout, $channel, "TRX-{$channel}-1122");

            $this->assertNotNull($entry);
            $this->assertSame(AccountingEvent::PAYOUT_DISBURSED->value, $entry->financial_event);
            $this->assertSame("payout_disbursed_{$payout->id}", $entry->idempotency_key);

            $entry->load('lines.account');
            $this->assertCount(2, $entry->lines);
            $this->assertEquals(500.00, (float) $entry->lines->sum('debit'));
            $this->assertEquals(500.00, (float) $entry->lines->sum('credit'));

            $debitLine = $entry->lines->firstWhere('debit', '>', 0);
            $this->assertNotNull($debitLine);
            $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
            $this->assertSame($this->seller->id, $debitLine->seller_id);

            $creditLine = $entry->lines->firstWhere('credit', '>', 0);
            $this->assertNotNull($creditLine);
            $this->assertSame('gateway_clearing', $creditLine->account->system_slug);
            $this->assertSame('500.00', (string) $creditLine->credit);
            $this->assertNull($creditLine->seller_id);
        }
    }

    /**
     * Test 4: Payout rejected restores liability: debits reserved_payout_payable, credits seller_wallet_payable.
     */
    public function test_payout_rejected_restores_liability(): void
    {
        $payout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REJECTED,
            'amount' => 1200.00,
            'running_balance' => 5000.00,
            'reference_note' => 'Payout rejected by finance team',
        ]);

        $reason = 'Bank account number does not match registered trade license.';
        $entry = $this->handler->recordPayoutRejected($payout, $reason);

        $this->assertNotNull($entry);
        $this->assertSame(AccountingEvent::PAYOUT_REJECTED->value, $entry->financial_event);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame("payout_rejected_{$payout->id}", $entry->idempotency_key);
        $this->assertSame(WalletTransaction::class, $entry->reference_type);
        $this->assertSame($payout->id, $entry->reference_id);
        $this->assertStringContainsString($reason, $entry->description);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);
        $this->assertEquals(1200.00, (float) $entry->lines->sum('debit'));
        $this->assertEquals(1200.00, (float) $entry->lines->sum('credit'));

        // Debit: reserved_payout_payable (seller scoped)
        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertSame('1200.00', (string) $debitLine->debit);
        $this->assertSame('0.00', (string) $debitLine->credit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        // Credit: seller_wallet_payable (seller scoped)
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('seller_wallet_payable', $creditLine->account->system_slug);
        $this->assertSame('0.00', (string) $creditLine->debit);
        $this->assertSame('1200.00', (string) $creditLine->credit);
        $this->assertSame($this->seller->id, $creditLine->seller_id);
    }

    /**
     * Test 5: Idempotency: duplicate calls return existing journal entry without creating new lines.
     */
    public function test_idempotency_duplicate_calls_return_existing_journal_entry(): void
    {
        $payout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 750.00,
            'running_balance' => 4250.00,
            'reference_note' => 'Idempotency test payout',
        ]);

        $firstEntry = $this->handler->recordPayoutRequested($payout);
        $this->assertNotNull($firstEntry);

        $initialEntriesCount = AccountingJournalEntry::count();
        $initialLinesCount = AccountingJournalLine::count();

        // Second call with identical payout transaction
        $secondEntry = $this->handler->recordPayoutRequested($payout);
        $this->assertNotNull($secondEntry);
        $this->assertSame($firstEntry->id, $secondEntry->id);
        $this->assertSame($initialEntriesCount, AccountingJournalEntry::count());
        $this->assertSame($initialLinesCount, AccountingJournalLine::count());

        // Duplicate test for recordPayoutDisbursed
        $disbursedEntry1 = $this->handler->recordPayoutDisbursed($payout, 'bank', 'TRX-DUP-1');
        $entriesCountAfterDisburse = AccountingJournalEntry::count();
        $linesCountAfterDisburse = AccountingJournalLine::count();

        $disbursedEntry2 = $this->handler->recordPayoutDisbursed($payout, 'bank', 'TRX-DUP-1');
        $this->assertSame($disbursedEntry1->id, $disbursedEntry2->id);
        $this->assertSame($entriesCountAfterDisburse, AccountingJournalEntry::count());
        $this->assertSame($linesCountAfterDisburse, AccountingJournalLine::count());

        // Duplicate test for recordPayoutRejected
        $rejectedPayout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REJECTED,
            'amount' => 300.00,
            'running_balance' => 4550.00,
            'reference_note' => 'Rejection idempotency test',
        ]);

        $rejectedEntry1 = $this->handler->recordPayoutRejected($rejectedPayout, 'Mismatch');
        $entriesCountAfterReject = AccountingJournalEntry::count();
        $linesCountAfterReject = AccountingJournalLine::count();

        $rejectedEntry2 = $this->handler->recordPayoutRejected($rejectedPayout, 'Mismatch');
        $this->assertSame($rejectedEntry1->id, $rejectedEntry2->id);
        $this->assertSame($entriesCountAfterReject, AccountingJournalEntry::count());
        $this->assertSame($linesCountAfterReject, AccountingJournalLine::count());
    }

    /**
     * Test 6: Zero/negative amount or unseeded accounts return null without exceptions.
     */
    public function test_zero_or_negative_amount_returns_null_and_missing_accounts_are_refused(): void
    {
        // Zero amount
        $zeroPayout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 0.00,
            'running_balance' => 5000.00,
            'reference_note' => 'Zero amount test',
        ]);

        $this->assertNull($this->handler->recordPayoutRequested($zeroPayout));
        $this->assertNull($this->handler->recordPayoutDisbursed($zeroPayout));
        $this->assertNull($this->handler->recordPayoutRejected($zeroPayout, 'Test reason'));

        // Negative amount
        $negativePayout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => -250.00,
            'running_balance' => 5000.00,
            'reference_note' => 'Negative amount test',
        ]);

        $this->assertNull($this->handler->recordPayoutRequested($negativePayout));
        $this->assertNull($this->handler->recordPayoutDisbursed($negativePayout));
        $this->assertNull($this->handler->recordPayoutRejected($negativePayout, 'Test reason'));

        // Unseeded accounts simulation
        AccountingJournalLine::query()->delete();
        AccountingJournalEntry::query()->delete();
        AccountingAccount::query()->delete();

        $validPayout = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 1000.00,
            'running_balance' => 4000.00,
            'reference_note' => 'Valid payout with unseeded accounts',
        ]);

        // ACC-6: each posting refuses loudly instead of skipping the journal.
        foreach ([
            fn () => $this->handler->recordPayoutRequested($validPayout),
            fn () => $this->handler->recordPayoutDisbursed($validPayout),
            fn () => $this->handler->recordPayoutRejected($validPayout, 'Test unseeded'),
        ] as $post) {
            try {
                $post();
                $this->fail('A posting with missing accounts should be refused.');
            } catch (MissingAccountException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
