<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-3 / MON-4 (plan changes) and SEC-4 / MON-5 (wallet payments).
 */
class SellerInvoiceSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerProfile $profile;

    private SellerSubscription $subscription;

    private SellerPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Billing Store',
            'store_slug' => 'billing-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $starter = SellerPlan::create([
            'name' => 'Starter', 'slug' => 'starter', 'subscription_price' => 500,
            'billing_cycle_days' => 30, 'max_active_products' => 5, 'is_active' => true,
        ]);
        $this->proPlan = SellerPlan::create([
            'name' => 'Pro', 'slug' => 'pro', 'subscription_price' => 1299,
            'billing_cycle_days' => 30, 'max_active_products' => 50, 'is_active' => true,
        ]);
        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $starter->id,
            'status' => 'active',
            'starts_at' => now()->subDays(20),
            'ends_at' => now()->addDays(10),
        ]);
        SellerWallet::create(['seller_id' => $this->seller->id, 'available_balance' => 5000, 'escrow_held_balance' => 0]);

        $this->actingAs($this->seller);
    }

    private function service(): SellerInvoiceSettlementService
    {
        return app(SellerInvoiceSettlementService::class);
    }

    public function test_a_retired_plan_cannot_be_chosen(): void
    {
        $retired = SellerPlan::create([
            'name' => 'Old Unlimited', 'slug' => 'old-unlimited', 'subscription_price' => 0,
            'max_active_products' => null, 'is_active' => false,
        ]);

        Livewire::test(SubscriptionBilling::class)->call('upgradePlan', $retired->id);

        $this->assertSame(0, SellerInvoice::count());
        $this->assertSame(1, SellerSubscription::where('seller_id', $this->seller->id)->count());
        $this->assertSame('active', $this->subscription->fresh()->status);
    }

    public function test_paid_plan_change_leaves_exactly_one_live_subscription(): void
    {
        $invoice = $this->service()->startPlanChange($this->seller, $this->proPlan->id);

        $this->assertSame('active', $this->subscription->fresh()->status, 'current plan stays until the bill is paid');

        $this->service()->payFromWallet($this->seller, $invoice->id);

        $live = SellerSubscription::where('seller_id', $this->seller->id)
            ->whereIn('status', ['active', 'grace_period'])->get();
        $this->assertCount(1, $live);
        $this->assertSame($this->proPlan->id, $live->first()->plan_id);
        $this->assertSame('expired', $this->subscription->fresh()->status);
    }

    public function test_an_abandoned_plan_change_never_puts_the_store_into_grace(): void
    {
        $this->service()->startPlanChange($this->seller, $this->proPlan->id);
        $this->travel(11)->days();

        Artisan::call('monetization:check-grace-periods');

        $this->assertSame('active', $this->profile->fresh()->monetization_status);
    }

    public function test_choosing_a_plan_twice_replaces_the_first_unpaid_bill(): void
    {
        $first = $this->service()->startPlanChange($this->seller, $this->proPlan->id);
        $second = $this->service()->startPlanChange($this->seller, $this->proPlan->id);

        $this->assertSame('void', $first->fresh()->status);
        $this->assertSame('unpaid', $second->fresh()->status);
        $this->assertSame(1, SellerSubscription::where('status', 'payment_due')->count());
    }

    public function test_paying_the_same_bill_twice_takes_the_money_once(): void
    {
        $invoice = $this->renewalInvoice();

        $this->service()->payFromWallet($this->seller, $invoice->id);

        try {
            $this->service()->payFromWallet($this->seller, $invoice->id);
            $this->fail('second payment should be refused');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already paid', $exception->getMessage());
        }

        $this->assertSame(4500.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
        $this->assertSame(1, SellerPayment::where('invoice_id', $invoice->id)->count());
    }

    public function test_page_reports_a_second_click_instead_of_charging_again(): void
    {
        $invoice = $this->renewalInvoice();

        Livewire::test(SubscriptionBilling::class)
            ->call('payWithWallet', $invoice->id)
            ->call('payWithWallet', $invoice->id);

        $this->assertSame(4500.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
    }

    public function test_early_renewal_keeps_the_days_already_paid_for(): void
    {
        $endsBefore = $this->subscription->ends_at->copy();

        $this->service()->payFromWallet($this->seller, $this->renewalInvoice()->id);

        $this->assertTrue($this->subscription->fresh()->ends_at->equalTo($endsBefore->addDays(30)));
    }

    public function test_paying_a_non_subscription_bill_does_not_extend_the_plan(): void
    {
        $endsBefore = $this->subscription->ends_at->copy();
        $invoice = $this->renewalInvoice(['invoice_type' => SellerInvoice::TYPE_LISTING_PACK]);

        $this->service()->payFromWallet($this->seller, $invoice->id);

        $this->assertTrue($this->subscription->fresh()->ends_at->equalTo($endsBefore));
    }

    public function test_not_enough_wallet_money_is_refused_without_changes(): void
    {
        SellerWallet::where('seller_id', $this->seller->id)->update(['available_balance' => 100]);
        $invoice = $this->renewalInvoice();

        $this->expectException(DomainException::class);

        try {
            $this->service()->payFromWallet($this->seller, $invoice->id);
        } finally {
            $this->assertSame('unpaid', $invoice->fresh()->status);
        }
    }

    public function test_bill_raised_before_the_fix_still_switches_plan_once(): void
    {
        $legacy = $this->renewalInvoice([
            'total_amount' => 1299, 'subtotal' => 1299,
            'metadata' => ['target_plan_id' => $this->proPlan->id, 'plan_name' => 'Pro'],
        ]);

        $this->service()->payFromWallet($this->seller, $legacy->id);

        $live = SellerSubscription::where('seller_id', $this->seller->id)->where('status', 'active')->get();
        $this->assertCount(1, $live);
        $this->assertSame($this->proPlan->id, $live->first()->plan_id);
    }

    public function test_wallet_payment_during_grace_returns_the_store_to_active(): void
    {
        $this->subscription->update(['status' => 'grace_period', 'ends_at' => now()->subDay(), 'grace_period_ends_at' => now()->addDays(2)]);
        $this->profile->update(['monetization_status' => 'grace_period']);

        $this->service()->payFromWallet($this->seller, $this->renewalInvoice()->id);

        $this->assertSame('active', $this->profile->fresh()->monetization_status);
        $this->assertSame('active', $this->subscription->fresh()->status);
        $this->assertTrue($this->subscription->fresh()->ends_at->greaterThan(now()->addDays(29)));
    }

    public function test_auto_renewal_run_twice_charges_the_wallet_once(): void
    {
        $this->subscription->update(['ends_at' => now()->addDay(), 'auto_renew' => true]);

        $this->artisan('monetization:process-renewals')->assertSuccessful();
        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $this->assertSame(4500.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
        $this->assertSame(1, SellerPayment::where('seller_id', $this->seller->id)->count());
    }

    public function test_a_free_plan_switch_does_not_lift_a_suspension_an_admin_imposed(): void
    {
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id]);
        app(MonetizationSuspensionService::class)->suspendSeller($this->seller, 'Counterfeit goods', $admin);
        $free = SellerPlan::create(['name' => 'Free', 'slug' => 'free', 'subscription_price' => 0, 'max_active_products' => 3, 'is_active' => true]);

        $this->service()->startPlanChange($this->seller, $free->id);

        $this->assertSame('suspended', $this->profile->fresh()->monetization_status);
    }

    public function test_paying_a_side_bill_does_not_lift_suspension_while_the_renewal_is_unpaid(): void
    {
        $this->renewalInvoice();
        app(MonetizationSuspensionService::class)->suspendSeller($this->seller, 'Renewal not paid');

        $this->service()->payFromWallet($this->seller, $this->renewalInvoice(['invoice_type' => SellerInvoice::TYPE_LISTING_PACK, 'total_amount' => 100, 'subtotal' => 100])->id);

        $this->assertSame('suspended', $this->profile->fresh()->monetization_status);
    }

    public function test_old_plan_bill_is_voided_when_the_plan_change_is_paid_and_cannot_revive_it(): void
    {
        $oldRenewal = $this->renewalInvoice();
        $change = $this->service()->startPlanChange($this->seller, $this->proPlan->id);
        $this->service()->payFromWallet($this->seller, $change->id);

        $this->assertSame('void', $oldRenewal->fresh()->status);

        $this->expectException(DomainException::class);
        $this->service()->payFromWallet($this->seller, $oldRenewal->id);
    }

    public function test_an_ended_plan_cannot_be_renewed_even_with_an_unpaid_bill(): void
    {
        $this->subscription->update(['status' => SellerSubscription::STATUS_EXPIRED]);
        $invoice = $this->renewalInvoice();

        try {
            $this->service()->payFromWallet($this->seller, $invoice->id);
            $this->fail('renewing an ended plan should be refused');
        } catch (DomainException) {
            $this->assertSame('unpaid', $invoice->fresh()->status);
            $this->assertSame(5000.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function renewalInvoice(array $overrides = []): SellerInvoice
    {
        return SellerInvoice::create(array_merge([
            'invoice_number' => 'SINV-TEST-'.uniqid(),
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 500,
            'total_amount' => 500,
            'status' => 'unpaid',
            'due_date' => now()->toDateString(),
        ], $overrides));
    }
}
