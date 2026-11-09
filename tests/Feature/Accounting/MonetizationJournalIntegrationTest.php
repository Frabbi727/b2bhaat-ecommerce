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
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Accounting\EventPipeline\MonetizationJournalHandler;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonetizationJournalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerPlan $plan;

    protected SellerSubscription $subscription;

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

        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Standard Factory Store',
            'store_slug' => 'standard-factory',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->plan = SellerPlan::create([
            'name' => 'Pro Merchant',
            'slug' => 'pro',
            'subscription_price' => 500.00,
            'billing_cycle_days' => 30,
            'max_active_products' => 100,
            'retail_commission_rate' => 5.00,
            'wholesale_commission_rate' => 2.50,
            'is_active' => true,
        ]);

        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'auto_renew' => true,
        ]);
    }

    public function test_verified_subscription_payment_via_gateway_debits_gateway_clearing_and_credits_subscription_revenue(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 500.00,
            'payment_method' => SellerPayment::METHOD_BKASH,
            'transaction_reference' => 'BKASH_TRX_9910',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $journal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")
            ->with(['lines.account'])
            ->first();

        $this->assertNotNull($journal);
        $this->assertEquals(AccountingEvent::SUBSCRIPTION_PAID->value, $journal->financial_event);
        $this->assertEquals(JournalEntryStatus::POSTED, $journal->status);
        $this->assertEquals(SellerPayment::class, $journal->reference_type);
        $this->assertEquals($payment->id, $journal->reference_id);
        $this->assertStringContainsString('SINV-SUB-001', $journal->description);
        $this->assertStringContainsString('bkash', $journal->description);
        $this->assertEquals('monetization', $journal->metadata['source_module']);
        $this->assertEquals((string) $payment->id, $journal->metadata['source_transaction_id']);

        $this->assertCount(2, $journal->lines);

        // Debit: Payment Gateway Clearing (gateway_clearing)
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEquals('gateway_clearing', $debitLine->account->system_slug);
        $this->assertEquals('500.00', (string) $debitLine->debit);
        $this->assertEquals('0.00', (string) $debitLine->credit);

        // Credit: Seller Subscription Revenue (subscription_revenue)
        $creditLine = $journal->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals('subscription_revenue', $creditLine->account->system_slug);
        $this->assertEquals('0.00', (string) $creditLine->debit);
        $this->assertEquals('500.00', (string) $creditLine->credit);
        $this->assertEquals($this->seller->id, $creditLine->seller_id);
    }

    public function test_wallet_balance_subscription_payment_debits_seller_wallet_payable_and_credits_subscription_revenue(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-002',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 350.00,
            'total_amount' => 350.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 350.00,
            'payment_method' => SellerPayment::METHOD_WALLET_BALANCE,
            'transaction_reference' => 'WALLET_DEDUCT_001',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $journal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")
            ->with(['lines.account'])
            ->first();

        $this->assertNotNull($journal);
        $this->assertEquals(AccountingEvent::SUBSCRIPTION_PAID->value, $journal->financial_event);

        // Debit: Seller Available Wallet Payable (seller_wallet_payable, scoped by seller)
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEquals('seller_wallet_payable', $debitLine->account->system_slug);
        $this->assertEquals('350.00', (string) $debitLine->debit);
        $this->assertEquals($this->seller->id, $debitLine->seller_id);

        // Credit: Seller Subscription Revenue (subscription_revenue, scoped by seller)
        $creditLine = $journal->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals('subscription_revenue', $creditLine->account->system_slug);
        $this->assertEquals('350.00', (string) $creditLine->credit);
        $this->assertEquals($this->seller->id, $creditLine->seller_id);
    }

    public function test_listing_pack_payment_debits_gateway_clearing_and_credits_listing_pack_revenue(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-LP-001',
            'seller_id' => $this->seller->id,
            'invoice_type' => SellerInvoice::TYPE_LISTING_PACK,
            'subtotal' => 250.00,
            'total_amount' => 250.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 250.00,
            'payment_method' => SellerPayment::METHOD_NAGAD,
            'transaction_reference' => 'NAGAD_TRX_4401',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $journal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")
            ->with(['lines.account'])
            ->first();

        $this->assertNotNull($journal);
        $this->assertEquals(AccountingEvent::LISTING_PACK_PAID->value, $journal->financial_event);
        $this->assertStringContainsString('SINV-LP-001', $journal->description);
        $this->assertStringContainsString('nagad', $journal->description);

        // Debit: Gateway Clearing
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEquals('gateway_clearing', $debitLine->account->system_slug);
        $this->assertEquals('250.00', (string) $debitLine->debit);

        // Credit: Product Listing Addon Revenue (listing_pack_revenue)
        $creditLine = $journal->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals('listing_pack_revenue', $creditLine->account->system_slug);
        $this->assertEquals('250.00', (string) $creditLine->credit);
        $this->assertEquals($this->seller->id, $creditLine->seller_id);
    }

    public function test_bank_wire_payment_debits_bank_operating_and_credits_subscription_revenue(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-003',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 1200.00,
            'total_amount' => 1200.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 1200.00,
            'payment_method' => SellerPayment::METHOD_BANK_WIRE,
            'transaction_reference' => 'WIRE-IBBL-781923',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $journal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")
            ->with(['lines.account'])
            ->first();

        $this->assertNotNull($journal);
        $this->assertEquals(AccountingEvent::SUBSCRIPTION_PAID->value, $journal->financial_event);

        // Debit: Platform Operating Bank Account (bank_operating)
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEquals('bank_operating', $debitLine->account->system_slug);
        $this->assertEquals('1200.00', (string) $debitLine->debit);

        // Credit: Subscription Revenue
        $creditLine = $journal->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals('subscription_revenue', $creditLine->account->system_slug);
        $this->assertEquals('1200.00', (string) $creditLine->credit);
        $this->assertEquals($this->seller->id, $creditLine->seller_id);
    }

    /**
     * ACC-8: a waiver means the seller paid nothing, so no subscription revenue is booked (before, it booked revenue
     * balanced by a made-up expense).
     */
    public function test_admin_waiver_posts_no_journal_because_no_money_was_received(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-WAIVE-01',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 500.00,
            'payment_method' => SellerPayment::METHOD_ADMIN_WAIVER,
            'transaction_reference' => 'ADMIN-WAIVE-9901',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $this->assertNull(app(MonetizationJournalHandler::class)->recordSellerPayment($payment));
        $this->assertSame(0, AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->count());
        $this->assertSame(0, AccountingJournalEntry::where('financial_event', AccountingEvent::SUBSCRIPTION_PAID->value)->count());
    }

    public function test_idempotency_duplicate_calls_return_existing_journal_without_creating_new_lines(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-IDEM-01',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 500.00,
            'payment_method' => SellerPayment::METHOD_BKASH,
            'transaction_reference' => 'BKASH_IDEM_111',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $initialJournal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->first();
        $this->assertNotNull($initialJournal);
        $this->assertEquals(1, AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->count());
        $this->assertEquals(2, AccountingJournalLine::where('journal_entry_id', $initialJournal->id)->count());

        // Re-invoke manually via MonetizationJournalHandler
        $handler = app(MonetizationJournalHandler::class);
        $secondResult = $handler->recordSellerPayment($payment);

        $this->assertNotNull($secondResult);
        $this->assertEquals($initialJournal->id, $secondResult->id);
        $this->assertEquals(1, AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->count());
        $this->assertEquals(2, AccountingJournalLine::where('journal_entry_id', $initialJournal->id)->count());

        // Re-save payment (triggering model event)
        $payment->notes = 'Updated verification note';
        $payment->save();

        $this->assertEquals(1, AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->count());
        $this->assertEquals(2, AccountingJournalLine::where('journal_entry_id', $initialJournal->id)->count());
    }

    public function test_pending_or_failed_payment_does_not_post_journal(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-PEND-01',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->toDateString(),
        ]);

        // 1. Pending verification payment
        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 500.00,
            'payment_method' => SellerPayment::METHOD_BKASH,
            'transaction_reference' => 'PENDING_TRX_001',
            'status' => SellerPayment::STATUS_PENDING_VERIFICATION,
        ]);

        $this->assertNull(
            AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->first()
        );

        // 2. Failed payment
        $payment->update([
            'status' => SellerPayment::STATUS_FAILED,
        ]);

        $this->assertNull(
            AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->first()
        );

        // 3. Transition to successful posts the journal
        $payment->update([
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $journal = AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->first();
        $this->assertNotNull($journal);
        $this->assertEquals(AccountingEvent::SUBSCRIPTION_PAID->value, $journal->financial_event);
    }

    public function test_zero_amount_payment_does_not_post_journal(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-ZERO-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 0.00,
            'total_amount' => 0.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 0.00,
            'payment_method' => SellerPayment::METHOD_ADMIN_WAIVER,
            'status' => SellerPayment::STATUS_SUCCESSFUL,
        ]);

        $this->assertNull(
            AccountingJournalEntry::where('idempotency_key', "seller_payment_{$payment->id}")->first()
        );
    }

    public function test_a_payment_is_refused_when_accounting_accounts_are_missing(): void
    {
        AccountingAccount::query()->delete();

        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-UNSEEDED-01',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
        ]);

        // ACC-6: recording a successful payment with missing accounts is refused, not silently left out of the books.
        $this->expectException(MissingAccountException::class);

        SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 500.00,
            'payment_method' => SellerPayment::METHOD_BKASH,
            'status' => SellerPayment::STATUS_SUCCESSFUL,
        ]);
    }
}
