<?php

declare(strict_types=1);

namespace Tests\Feature\Monetization;

use App\Filament\Admin\Resources\SellerMonetization\Pages\ListSellerMonetizations;
use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Monetization\SellerInvoiceSettlementService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MON-11: a seller who paid a bill by bKash, Nagad or bank sends the TrxID. The bill stays unpaid until an admin
 * checks it, so a made-up reference can't hold off a grace period or suspension.
 */
class SellerPaymentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private SellerProfile $profile;

    private SellerInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
            'is_active' => true,
        ]);
        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'TrxID Store',
            'store_slug' => 'trxid-store',
            'monetization_status' => 'grace_period',
            'is_approved' => true,
        ]);
        $plan = SellerPlan::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'subscription_price' => 499.00,
            'max_active_products' => 75,
            'is_active' => true,
        ]);
        $subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'grace_period',
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDay(),
            'grace_period_ends_at' => now()->addDays(2),
        ]);
        $this->invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-TRX-1',
            'seller_id' => $this->seller->id,
            'subscription_id' => $subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function settlement(): SellerInvoiceSettlementService
    {
        return app(SellerInvoiceSettlementService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides = []): SellerPayment
    {
        return $this->settlement()->submitPaymentProof($this->seller, $this->invoice->id, $overrides + [
            'payment_method' => SellerPayment::METHOD_BKASH,
            'amount' => 499.00,
            'transaction_reference' => 'BK7XQ1TRX9',
        ]);
    }

    public function test_a_submission_waits_for_a_check_and_books_nothing(): void
    {
        $payment = $this->submit();

        $this->assertSame(SellerPayment::STATUS_PENDING_VERIFICATION, $payment->status);
        $this->assertSame(SellerInvoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame('grace_period', $this->profile->fresh()->monetization_status);
        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_wrong_amount_second_submission_and_used_reference_are_refused(): void
    {
        try {
            $this->submit(['amount' => 400]);
            $this->fail('A part payment must be refused');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('৳499.00', $exception->getMessage());
        }

        $this->submit();

        try {
            $this->submit(['transaction_reference' => 'OTHER-REF']);
            $this->fail('A second submission for the same bill must be refused');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('waiting to be checked', $exception->getMessage());
        }

        SellerPayment::create([
            'invoice_id' => $this->invoice->id,
            'seller_id' => $this->seller->id,
            'amount' => 10,
            'payment_method' => SellerPayment::METHOD_NAGAD,
            'transaction_reference' => 'NG-USED-1',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
        ]);
        SellerPayment::where('status', SellerPayment::STATUS_PENDING_VERIFICATION)->update(['status' => SellerPayment::STATUS_REJECTED]);

        $this->expectExceptionMessage('already recorded');
        $this->submit(['payment_method' => SellerPayment::METHOD_NAGAD, 'transaction_reference' => ' ng-used-1 ']);
    }

    public function test_approval_pays_the_bill_restores_the_store_and_books_the_payment(): void
    {
        $payment = $this->submit();

        $this->settlement()->approveSubmittedPayment($payment, $this->admin);

        $payment->refresh();
        $this->assertSame(SellerPayment::STATUS_SUCCESSFUL, $payment->status);
        $this->assertSame($this->admin->id, $payment->verified_by_user_id);
        $this->assertSame(SellerInvoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame('active', $this->profile->fresh()->monetization_status);
        $this->assertSame(1, AccountingJournalEntry::where('financial_event', 'subscription_paid')->count());

        $this->expectExceptionMessage('already been checked');
        $this->settlement()->approveSubmittedPayment($payment, $this->admin);
    }

    public function test_rejection_keeps_the_bill_unpaid_and_lets_the_seller_try_again(): void
    {
        $payment = $this->submit();

        $this->settlement()->rejectSubmittedPayment($payment, $this->admin, 'No such TrxID in our bKash statement');

        $this->assertSame(SellerPayment::STATUS_REJECTED, $payment->fresh()->status);
        $this->assertStringContainsString('No such TrxID', (string) $payment->fresh()->notes);
        $this->assertSame(SellerInvoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        Livewire::test(SubscriptionBilling::class)
            ->assertSee('Reference BK7XQ1TRX9 was not accepted: No such TrxID in our bKash statement');

        $this->assertSame(SellerPayment::STATUS_PENDING_VERIFICATION, $this->submit(['transaction_reference' => 'BK-RETRY-2'])->status);
    }

    public function test_seller_sends_and_admin_approves_through_the_panels(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SubscriptionBilling::class)
            ->callAction(TestAction::make('submitPayment')->arguments(['invoice' => $this->invoice->id]), [
                'payment_method' => SellerPayment::METHOD_NAGAD,
                'amount' => 499.00,
                'transaction_reference' => 'NG-PANEL-77',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Payment sent, waiting to be checked');

        $payment = SellerPayment::where('transaction_reference', 'NG-PANEL-77')->sole();

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSellerMonetizations::class)
            ->assertSee('NG-PANEL-77')
            ->callTableAction('checkSubmittedPayment', $this->profile, [
                'payment_id' => $payment->id,
                'decision' => 'approve',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(SellerInvoice::STATUS_PAID, $this->invoice->fresh()->status);
    }
}
