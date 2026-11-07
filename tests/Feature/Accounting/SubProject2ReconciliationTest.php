<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingPeriod;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubProject2ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected AccountingReconciliationService $reconciliationService;

    protected GeneralLedgerService $gl;

    protected JournalPostingService $journalPostingService;

    protected EscrowLedgerService $escrowLedger;

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

        $this->reconciliationService = app(AccountingReconciliationService::class);
        $this->gl = app(GeneralLedgerService::class);
        $this->journalPostingService = app(JournalPostingService::class);
        $this->escrowLedger = app(EscrowLedgerService::class);
    }

    protected function createSeller(string $name = 'Test Factory Seller'): User
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);

        return User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function createCustomer(string $name = 'Test Retail Customer'): User
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);

        return User::factory()->create([
            'role_id' => $customerRole->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function seedSellerWalletWithGl(User $seller, float $amount): SellerWallet
    {
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => $amount, 'escrow_held_balance' => 0.00]
        );

        if ($wallet->available_balance != $amount) {
            $wallet->update(['available_balance' => $amount, 'escrow_held_balance' => 0.00]);
        }

        $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: "seed_seller_wallet_{$seller->id}_{$amount}",
            description: "Seed available wallet for seller #{$seller->id}",
            lines: [
                new JournalLineData(
                    accountSlug: 'bank_operating',
                    debit: $amount,
                    credit: 0.00,
                    narration: 'Capital contribution for wallet test balance',
                ),
                new JournalLineData(
                    accountSlug: 'seller_wallet_payable',
                    debit: 0.00,
                    credit: $amount,
                    sellerId: $seller->id,
                    narration: 'Initial seller wallet payable liability',
                ),
            ],
            referenceType: SellerWallet::class,
            referenceId: $wallet->id,
        ));

        return $wallet->fresh();
    }

    /**
     * Test 1: Full payout lifecycle (request -> disburse) verifies seller wallet, escrow,
     * and reserved payouts at each stage with 0.00 discrepancy.
     */
    public function test_full_payout_lifecycle_reconciles_at_each_stage(): void
    {
        $seller = $this->createSeller();
        $this->seedSellerWalletWithGl($seller, 5000.00);

        // Stage 0: Initial state
        $initialSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($initialSeller['is_reconciled']);
        $this->assertSame(5000.00, $initialSeller['wallet_balance']);
        $this->assertSame(5000.00, $initialSeller['ledger_balance']);
        $this->assertSame(0.00, $initialSeller['discrepancy']);
        $this->assertSame(0.00, $initialSeller['escrow_wallet_balance']);
        $this->assertSame(0.00, $initialSeller['escrow_ledger_balance']);
        $this->assertSame(0.00, $initialSeller['escrow_discrepancy']);
        $this->assertSame(0.00, $initialSeller['reserved_wallet_balance']);
        $this->assertSame(0.00, $initialSeller['reserved_ledger_balance']);
        $this->assertSame(0.00, $initialSeller['reserved_discrepancy']);

        $initialPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($initialPayouts['is_reconciled']);
        $this->assertSame(0.00, $initialPayouts['total_pending_payouts']);
        $this->assertSame(0.00, $initialPayouts['ledger_reserved_balance']);
        $this->assertSame(0.00, $initialPayouts['discrepancy']);

        // Stage 1: Request payout of 1500.00
        $payout = $this->escrowLedger->requestPayout($seller, 1500.00, 'Merchant withdrawal');

        $requestedSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($requestedSeller['is_reconciled']);
        $this->assertSame(3500.00, $requestedSeller['wallet_balance']);
        $this->assertSame(3500.00, $requestedSeller['ledger_balance']);
        $this->assertSame(0.00, $requestedSeller['discrepancy']);
        $this->assertSame(1500.00, $requestedSeller['reserved_wallet_balance']);
        $this->assertSame(1500.00, $requestedSeller['reserved_ledger_balance']);
        $this->assertSame(0.00, $requestedSeller['reserved_discrepancy']);

        $requestedPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($requestedPayouts['is_reconciled']);
        $this->assertSame(1500.00, $requestedPayouts['total_pending_payouts']);
        $this->assertSame(1500.00, $requestedPayouts['ledger_reserved_balance']);
        $this->assertSame(0.00, $requestedPayouts['discrepancy']);

        // Stage 2: Disburse payout
        $this->escrowLedger->disbursePayout($payout, 'TRX-BANK-9988', 'bank');

        $disbursedSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($disbursedSeller['is_reconciled']);
        $this->assertSame(3500.00, $disbursedSeller['wallet_balance']);
        $this->assertSame(3500.00, $disbursedSeller['ledger_balance']);
        $this->assertSame(0.00, $disbursedSeller['discrepancy']);
        $this->assertSame(0.00, $disbursedSeller['reserved_wallet_balance']);
        $this->assertSame(0.00, $disbursedSeller['reserved_ledger_balance']);
        $this->assertSame(0.00, $disbursedSeller['reserved_discrepancy']);

        $disbursedPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($disbursedPayouts['is_reconciled']);
        $this->assertSame(0.00, $disbursedPayouts['total_pending_payouts']);
        $this->assertSame(0.00, $disbursedPayouts['ledger_reserved_balance']);
        $this->assertSame(0.00, $disbursedPayouts['discrepancy']);
    }

    /**
     * Test 2: Payout rejection verifies seller wallet, escrow, and reserved payouts with 0.00 discrepancy.
     */
    public function test_payout_rejection_reconciles_cleanly(): void
    {
        $seller = $this->createSeller();
        $this->seedSellerWalletWithGl($seller, 5000.00);

        // Request payout of 2000.00
        $payout = $this->escrowLedger->requestPayout($seller, 2000.00, 'Withdrawal for review');

        $midSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($midSeller['is_reconciled']);
        $this->assertSame(3000.00, $midSeller['wallet_balance']);
        $this->assertSame(2000.00, $midSeller['reserved_wallet_balance']);

        $midPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($midPayouts['is_reconciled']);
        $this->assertSame(2000.00, $midPayouts['total_pending_payouts']);

        // Reject payout
        $this->escrowLedger->rejectPayout($payout, 'Invalid routing number');

        $rejectedSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($rejectedSeller['is_reconciled']);
        $this->assertSame(5000.00, $rejectedSeller['wallet_balance']);
        $this->assertSame(5000.00, $rejectedSeller['ledger_balance']);
        $this->assertSame(0.00, $rejectedSeller['discrepancy']);
        $this->assertSame(0.00, $rejectedSeller['reserved_wallet_balance']);
        $this->assertSame(0.00, $rejectedSeller['reserved_ledger_balance']);
        $this->assertSame(0.00, $rejectedSeller['reserved_discrepancy']);

        $rejectedPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($rejectedPayouts['is_reconciled']);
        $this->assertSame(0.00, $rejectedPayouts['total_pending_payouts']);
        $this->assertSame(0.00, $rejectedPayouts['ledger_reserved_balance']);
        $this->assertSame(0.00, $rejectedPayouts['discrepancy']);
    }

    /**
     * Test 3: verifyReservedPayouts() matches pending payouts across multiple sellers.
     */
    public function test_verify_reserved_payouts_matches_across_multiple_sellers(): void
    {
        $seller1 = $this->createSeller('Seller 1');
        $seller2 = $this->createSeller('Seller 2');

        $this->seedSellerWalletWithGl($seller1, 4000.00);
        $this->seedSellerWalletWithGl($seller2, 6000.00);

        // Seller 1 requests 1000.00
        $this->escrowLedger->requestPayout($seller1, 1000.00);

        // Seller 2 requests 2500.00
        $this->escrowLedger->requestPayout($seller2, 2500.00);

        // Combined pending payouts: 3500.00
        $allPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertTrue($allPayouts['is_reconciled']);
        $this->assertSame(3500.00, $allPayouts['total_pending_payouts']);
        $this->assertSame(3500.00, $allPayouts['ledger_reserved_balance']);
        $this->assertSame(0.00, $allPayouts['discrepancy']);

        // Individual seller 1 verification
        $res1 = $this->reconciliationService->verifySellerWallet($seller1);
        $this->assertTrue($res1['is_reconciled']);
        $this->assertSame(1000.00, $res1['reserved_wallet_balance']);
        $this->assertSame(1000.00, $res1['reserved_ledger_balance']);
        $this->assertSame(0.00, $res1['reserved_discrepancy']);

        // Individual seller 2 verification
        $res2 = $this->reconciliationService->verifySellerWallet($seller2);
        $this->assertTrue($res2['is_reconciled']);
        $this->assertSame(2500.00, $res2['reserved_wallet_balance']);
        $this->assertSame(2500.00, $res2['reserved_ledger_balance']);
        $this->assertSame(0.00, $res2['reserved_discrepancy']);
    }

    /**
     * Test 4: Injected tampering in pending payouts or reserved liability is detected
     * with is_reconciled = false and exact discrepancy.
     */
    public function test_injected_tampering_in_pending_payouts_is_detected(): void
    {
        $seller = $this->createSeller();
        $this->seedSellerWalletWithGl($seller, 5000.00);

        $payout = $this->escrowLedger->requestPayout($seller, 1000.00);

        // Verify clean baseline
        $this->assertTrue($this->reconciliationService->verifyReservedPayouts()['is_reconciled']);
        $this->assertTrue($this->reconciliationService->verifySellerWallet($seller)['is_reconciled']);

        // Tamper 1: Alter payout record in DB directly (+400.00) without posting GL journal
        $payout->updateQuietly(['amount' => 1400.00]);

        $tamperedPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertFalse($tamperedPayouts['is_reconciled']);
        $this->assertSame(1400.00, $tamperedPayouts['total_pending_payouts']);
        $this->assertSame(1000.00, $tamperedPayouts['ledger_reserved_balance']);
        $this->assertSame(400.00, $tamperedPayouts['discrepancy']);

        $tamperedSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertFalse($tamperedSeller['is_reconciled']);
        $this->assertSame(1400.00, $tamperedSeller['reserved_wallet_balance']);
        $this->assertSame(1000.00, $tamperedSeller['reserved_ledger_balance']);
        $this->assertSame(400.00, $tamperedSeller['reserved_discrepancy']);

        // Reset payout record
        $payout->updateQuietly(['amount' => 1000.00]);

        // Tamper 2: Inject rogue GL journal entry that credits reserved_payout_payable by 250.00
        $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'rogue_reserved_payout_injection_001',
            description: 'Unauthorized rogue adjustment to reserved payout liability',
            lines: [
                new JournalLineData(
                    accountSlug: 'bank_operating',
                    debit: 250.00,
                    credit: 0.00,
                    narration: 'Rogue debit',
                ),
                new JournalLineData(
                    accountSlug: 'reserved_payout_payable',
                    debit: 0.00,
                    credit: 250.00,
                    sellerId: $seller->id,
                    narration: 'Rogue credit to reserved payout payable',
                ),
            ],
        ));

        $tamperedGlPayouts = $this->reconciliationService->verifyReservedPayouts();
        $this->assertFalse($tamperedGlPayouts['is_reconciled']);
        $this->assertSame(1000.00, $tamperedGlPayouts['total_pending_payouts']);
        $this->assertSame(1250.00, $tamperedGlPayouts['ledger_reserved_balance']);
        $this->assertSame(-250.00, $tamperedGlPayouts['discrepancy']);

        $tamperedGlSeller = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertFalse($tamperedGlSeller['is_reconciled']);
        $this->assertSame(1000.00, $tamperedGlSeller['reserved_wallet_balance']);
        $this->assertSame(1250.00, $tamperedGlSeller['reserved_ledger_balance']);
        $this->assertSame(-250.00, $tamperedGlSeller['reserved_discrepancy']);
    }

    /**
     * Test 5: Customer refund lifecycle (order cancel -> pending refund -> refund disbursed)
     * verifies verifyCustomerRefundPayables() at each stage with 0.00 discrepancy.
     */
    public function test_customer_refund_lifecycle_reconciles_at_each_stage(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $order = Order::create([
            'order_number' => 'ORD-REF-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1200.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-REF-REC-001-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 1100.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // Establish prepaid payment & escrow hold
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        $this->escrowLedger->holdInEscrow($subOrder);

        // Stage 0: Initial state (no pending refunds)
        $initialRefunds = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertTrue($initialRefunds['is_reconciled']);
        $this->assertSame(0.00, $initialRefunds['total_pending_refunds']);
        $this->assertSame(0.00, $initialRefunds['ledger_refund_payable_balance']);
        $this->assertSame(0.00, $initialRefunds['discrepancy']);

        // Stage 1: Cancel sub-order -> generates Dr seller_escrow_payable / Cr customer_refund_payable (1200.00)
        $this->escrowLedger->refundCancelledEscrow($subOrder, 'Customer requested order cancellation');

        // Create pending refund record
        $refund = Refund::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder->id,
            'amount' => 1200.00,
            'reason' => 'Customer requested order cancellation',
            'status' => Refund::STATUS_PENDING,
            'method' => 'bkash',
        ]);

        $pendingRefunds = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertTrue($pendingRefunds['is_reconciled']);
        $this->assertSame(1200.00, $pendingRefunds['total_pending_refunds']);
        $this->assertSame(1200.00, $pendingRefunds['ledger_refund_payable_balance']);
        $this->assertSame(0.00, $pendingRefunds['discrepancy']);

        // Stage 2: Disburse/settle refund -> generates Dr customer_refund_payable / Cr gateway_clearing
        $refund->update([
            'status' => Refund::STATUS_PAID,
            'reference' => 'BKASH-REF-776655',
            'paid_at' => now(),
        ]);

        app(CustomerRefundDisbursementJournalHandler::class)->recordRefundDisbursed($refund);

        $disbursedRefunds = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertTrue($disbursedRefunds['is_reconciled']);
        $this->assertSame(0.00, $disbursedRefunds['total_pending_refunds']);
        $this->assertSame(0.00, $disbursedRefunds['ledger_refund_payable_balance']);
        $this->assertSame(0.00, $disbursedRefunds['discrepancy']);
    }

    /**
     * Test 6: Injected tampering in pending refunds or customer refund payable is detected.
     */
    public function test_injected_tampering_in_pending_refunds_is_detected(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $order = Order::create([
            'order_number' => 'ORD-TAMPER-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => ['address' => 'Khulna'],
            'total_amount' => 800.00,
            'shipping_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-TAMPER-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 800.00,
            'shipping_amount' => 0.00,
            'commission_amount' => 80.00,
        ]);

        // Post legitimate liability for pending refund
        $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: 'legit_refund_liability_seed_800',
            description: 'Approved refund liability for order',
            lines: [
                new JournalLineData(
                    accountSlug: 'bank_operating',
                    debit: 800.00,
                    credit: 0.00,
                    narration: 'Funding refund reserve',
                ),
                new JournalLineData(
                    accountSlug: 'customer_refund_payable',
                    debit: 0.00,
                    credit: 800.00,
                    customerId: $customer->id,
                    orderId: $order->id,
                    subOrderId: $subOrder->id,
                    narration: 'Customer refund payable',
                ),
            ],
        ));

        $refund = Refund::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder->id,
            'amount' => 800.00,
            'reason' => 'Defective merchandise',
            'status' => Refund::STATUS_PENDING,
            'method' => 'bkash',
        ]);

        // Baseline verification: clean reconciliation
        $baseline = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertTrue($baseline['is_reconciled']);
        $this->assertSame(800.00, $baseline['total_pending_refunds']);
        $this->assertSame(800.00, $baseline['ledger_refund_payable_balance']);
        $this->assertSame(0.00, $baseline['discrepancy']);

        // Tamper 1: Directly alter refund record amount in database (+200.00)
        $refund->updateQuietly(['amount' => 1000.00]);

        $tamperedRefund = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertFalse($tamperedRefund['is_reconciled']);
        $this->assertSame(1000.00, $tamperedRefund['total_pending_refunds']);
        $this->assertSame(800.00, $tamperedRefund['ledger_refund_payable_balance']);
        $this->assertSame(200.00, $tamperedRefund['discrepancy']);

        // Restore refund record
        $refund->updateQuietly(['amount' => 800.00]);

        // Tamper 2: Directly inject rogue GL journal entry (+300.00 to customer_refund_payable)
        $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'rogue_customer_refund_injection_001',
            description: 'Unauthorized rogue adjustment to customer refund payable liability',
            lines: [
                new JournalLineData(
                    accountSlug: 'bank_operating',
                    debit: 300.00,
                    credit: 0.00,
                    narration: 'Rogue debit',
                ),
                new JournalLineData(
                    accountSlug: 'customer_refund_payable',
                    debit: 0.00,
                    credit: 300.00,
                    narration: 'Rogue credit to customer refund payable',
                ),
            ],
        ));

        $tamperedGl = $this->reconciliationService->verifyCustomerRefundPayables();
        $this->assertFalse($tamperedGl['is_reconciled']);
        $this->assertSame(800.00, $tamperedGl['total_pending_refunds']);
        $this->assertSame(1100.00, $tamperedGl['ledger_refund_payable_balance']);
        $this->assertSame(-300.00, $tamperedGl['discrepancy']);
    }
}
