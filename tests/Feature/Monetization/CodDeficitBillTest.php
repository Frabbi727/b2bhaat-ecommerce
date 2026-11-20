<?php

declare(strict_types=1);

namespace Tests\Feature\Monetization;

use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Jobs\SendSmsJob;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerProfile;
use App\Models\SellerSuspensionEvent;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MON-11: a seller who collected COD cash themselves owes the platform its commission, which can leave their wallet
 * below zero. After the grace days (7 by default) the seller gets a bill for what is still owed, paid by bKash, Nagad
 * or bank, and the payment brings the wallet back up.
 */
class CodDeficitBillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private SellerWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
        ]);
        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
            'phone' => '01712345678',
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Cash Store',
            'store_slug' => 'cash-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->wallet = SellerWallet::create(['seller_id' => $this->seller->id, 'available_balance' => 0, 'escrow_held_balance' => 0]);
    }

    /**
     * Balance goes 100 → −50 on the given day and stays there.
     */
    private function goNegative(int $daysAgo): void
    {
        $this->walletEntry(WalletTransaction::TYPE_ESCROW_RELEASE, 100, 100, $daysAgo + 2);
        $this->walletEntry(WalletTransaction::TYPE_COD_SELF_COLLECTED, 150, -50, $daysAgo);
        $this->wallet->update(['available_balance' => -50]);
    }

    private function walletEntry(string $type, float $amount, float $runningBalance, int $daysAgo): void
    {
        $entry = WalletTransaction::create([
            'seller_wallet_id' => $this->wallet->id,
            'transaction_type' => $type,
            'amount' => $amount,
            'running_balance' => $runningBalance,
        ]);
        $entry->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
    }

    private function deficitBills()
    {
        return SellerInvoice::where('seller_id', $this->seller->id)->where('invoice_type', SellerInvoice::TYPE_COD_COMMISSION_DEFICIT);
    }

    public function test_a_bill_is_raised_once_after_the_grace_days(): void
    {
        $this->goNegative(daysAgo: 3);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $this->assertSame(0, $this->deficitBills()->count(), 'Still inside the 7 grace days');

        $this->travel(5)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $bill = $this->deficitBills()->sole();
        $this->assertSame(SellerInvoice::STATUS_UNPAID, $bill->status);
        $this->assertEqualsWithDelta(50.0, (float) $bill->total_amount, 0.001);
    }

    public function test_paying_the_bill_brings_the_wallet_back_to_zero_and_credits_the_seller_in_the_ledger(): void
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $bill = $this->deficitBills()->sole();

        $settlement = app(SellerInvoiceSettlementService::class);
        $payment = $settlement->submitPaymentProof($this->seller, $bill->id, [
            'payment_method' => SellerPayment::METHOD_BKASH,
            'amount' => 50,
            'transaction_reference' => 'BK-COD-DEFICIT-1',
        ]);
        $settlement->approveSubmittedPayment($payment, $this->admin);

        $this->assertSame(SellerInvoice::STATUS_PAID, $bill->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $this->wallet->fresh()->available_balance, 0.001);
        $this->assertTrue(WalletTransaction::where('seller_wallet_id', $this->wallet->id)
            ->where('transaction_type', WalletTransaction::TYPE_COD_DEFICIT_PAYMENT)
            ->where('amount', 50)
            ->exists());

        $credit = AccountingJournalLine::whereHas('journalEntry', fn ($entry) => $entry->where('financial_event', 'cod_deficit_paid'))
            ->whereHas('account', fn ($account) => $account->where('system_slug', 'seller_wallet_payable'))
            ->sole();
        $this->assertSame($this->seller->id, $credit->seller_id);
        $this->assertEqualsWithDelta(50.0, (float) $credit->credit, 0.001);
    }

    public function test_the_bill_cannot_be_paid_from_the_wallet_or_waived(): void
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $bill = $this->deficitBills()->sole();
        $settlement = app(SellerInvoiceSettlementService::class);

        try {
            $settlement->payFromWallet($this->seller, $bill->id);
            $this->fail('A wallet that is below zero cannot pay its own deficit');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('bKash, Nagad or bank', $exception->getMessage());
        }

        $this->expectExceptionMessage('cannot be waived');
        $settlement->recordOfflinePayment($this->seller, $bill->id, ['payment_method' => SellerPayment::METHOD_ADMIN_WAIVER], $this->admin);
    }

    public function test_an_unpaid_bill_is_withdrawn_once_earnings_cover_the_deficit(): void
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->walletEntry(WalletTransaction::TYPE_ESCROW_RELEASE, 80, 30, 0);
        $this->wallet->update(['available_balance' => 30]);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->assertSame(SellerInvoice::STATUS_VOID, $this->deficitBills()->sole()->status);
    }

    public function test_the_seller_sees_what_the_bill_is_for_and_how_to_pay_it(): void
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SubscriptionBilling::class)
            ->assertSee('Commission owed on cash you collected')
            ->assertSee('I paid by bKash, Nagad or bank')
            ->assertDontSee('Pay from Wallet')
            ->assertDontSee('Top-up Wallet');
    }

    /**
     * Review R-6: earnings cover the deficit while the seller's bKash payment for the bill waits to be checked.
     * The bill stays, so the admin can still approve the payment and the money is credited.
     */
    public function test_a_bill_with_a_payment_waiting_to_be_checked_is_not_withdrawn(): void
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $bill = $this->deficitBills()->sole();

        $settlement = app(SellerInvoiceSettlementService::class);
        $payment = $settlement->submitPaymentProof($this->seller, $bill->id, [
            'payment_method' => SellerPayment::METHOD_BKASH,
            'amount' => 50,
            'transaction_reference' => 'BK-COD-WAITING-1',
        ]);

        $this->walletEntry(WalletTransaction::TYPE_ESCROW_RELEASE, 80, 30, 0);
        $this->wallet->update(['available_balance' => 30]);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->assertSame(SellerInvoice::STATUS_UNPAID, $bill->fresh()->status);

        $settlement->approveSubmittedPayment($payment, $this->admin);
        $this->assertSame(SellerInvoice::STATUS_PAID, $bill->fresh()->status);
        $this->assertEqualsWithDelta(80.0, (float) $this->wallet->fresh()->available_balance, 0.001);
    }

    /**
     * Review R-7: at 02:00 in Dhaka (20:00 UTC the day before) the bill is dated, numbered and due by the Dhaka
     * date the seller sees.
     */
    public function test_bill_dates_follow_the_dhaka_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 20:00:00', 'UTC'));
        $this->goNegative(daysAgo: 8);

        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $bill = $this->deficitBills()->sole();
        $this->assertStringStartsWith('SINV-20261121-', $bill->invoice_number);
        $this->assertSame('2026-11-28', $bill->due_date->toDateString());
    }

    /**
     * Review R-7: a bill due "yesterday" in Dhaka blocks a payout even before midnight UTC.
     */
    public function test_a_bill_due_yesterday_in_dhaka_blocks_a_payout_before_midnight_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 20:00:00', 'UTC'));
        SellerInvoice::create([
            'invoice_number' => 'SINV-DUE-1',
            'seller_id' => $this->seller->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 100,
            'total_amount' => 100,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => '2026-11-20',
        ]);
        $this->wallet->update(['available_balance' => 1000]);

        $this->expectExceptionMessage('SINV-DUE-1');
        app(EscrowLedgerService::class)->requestPayout($this->seller, 500);
    }

    /**
     * Owner decision (item 7): a deficit bill is due 7 days after it is raised. If it is still unpaid the monetization
     * grace days (3 by default) after that, the store is suspended and the seller is told by SMS.
     */
    private function raiseBill(): SellerInvoice
    {
        $this->goNegative(daysAgo: 8);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        return $this->deficitBills()->sole();
    }

    private function storeStatus(): string
    {
        return (string) $this->seller->sellerProfile()->value('monetization_status');
    }

    public function test_an_overdue_bill_suspends_the_store_after_the_grace_days(): void
    {
        Queue::fake();
        $this->raiseBill();

        $this->travel(7 + 3)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $this->assertSame('active', $this->storeStatus(), 'Still inside the grace days after the due date');

        $this->travel(1)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->assertSame('suspended', $this->storeStatus());
        $event = SellerSuspensionEvent::where('seller_id', $this->seller->id)->sole();
        $this->assertSame(SellerSuspensionEvent::TRIGGER_AUTOMATED_BILLING, $event->trigger_type);
        $this->assertStringContainsString('cash you collected', $event->reason);
        Queue::assertPushed(SendSmsJob::class, 1);
    }

    public function test_paying_the_overdue_bill_restores_the_store(): void
    {
        Queue::fake();
        $bill = $this->raiseBill();
        $this->travel(11)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();
        $this->assertSame('suspended', $this->storeStatus());

        $settlement = app(SellerInvoiceSettlementService::class);
        $payment = $settlement->submitPaymentProof($this->seller, $bill->id, [
            'payment_method' => SellerPayment::METHOD_BKASH,
            'amount' => 50,
            'transaction_reference' => 'BK-COD-RESTORE-1',
        ]);
        $settlement->approveSubmittedPayment($payment, $this->admin);

        $this->assertSame('active', $this->storeStatus());
        $this->assertNotNull(SellerSuspensionEvent::where('seller_id', $this->seller->id)->sole()->restored_at);
    }

    public function test_a_store_stays_suspended_while_a_deficit_bill_is_unpaid(): void
    {
        Queue::fake();
        $this->raiseBill();
        $this->travel(11)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        // Paying some other bill does not lift a suspension the deficit bill caused
        app(MonetizationSuspensionService::class)->restoreAfterPayment($this->seller);

        $this->assertSame('suspended', $this->storeStatus());
    }

    public function test_a_store_is_restored_when_earnings_cover_the_deficit(): void
    {
        Queue::fake();
        $this->raiseBill();
        $this->travel(11)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->walletEntry(WalletTransaction::TYPE_ESCROW_RELEASE, 80, 30, 0);
        $this->wallet->update(['available_balance' => 30]);
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->assertSame(SellerInvoice::STATUS_VOID, $this->deficitBills()->sole()->status);
        $this->assertSame('active', $this->storeStatus());
    }

    public function test_a_store_whose_payment_waits_to_be_checked_is_not_suspended(): void
    {
        Queue::fake();
        $bill = $this->raiseBill();
        app(SellerInvoiceSettlementService::class)->submitPaymentProof($this->seller, $bill->id, [
            'payment_method' => SellerPayment::METHOD_BKASH,
            'amount' => 50,
            'transaction_reference' => 'BK-COD-PENDING-1',
        ]);

        $this->travel(11)->days();
        $this->artisan('monetization:bill-cod-deficits')->assertSuccessful();

        $this->assertSame('active', $this->storeStatus());
    }

    public function test_a_deficit_bill_not_yet_overdue_does_not_hold_back_a_store_in_its_grace_period(): void
    {
        $this->raiseBill();
        $this->seller->sellerProfile()->update(['monetization_status' => 'grace_period']);

        app(MonetizationSuspensionService::class)->restoreAfterPayment($this->seller);

        $this->assertSame('active', $this->storeStatus());
    }
}
