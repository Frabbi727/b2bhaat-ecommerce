<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Monetization\SellerInvoiceSettlementService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * MON-10 / ACC-10: a subscription is billed at most once for each period.
 */
class SellerBillingPeriodKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerPlan $plan;

    private SellerSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingChartOfAccountsSeeder::class);
        Queue::fake();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'phone' => '01711223344',
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Period Store',
            'store_slug' => 'period-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->plan = SellerPlan::create([
            'name' => 'Growth',
            'slug' => 'growth-period',
            'subscription_price' => 499.00,
            'billing_cycle_days' => 30,
            'is_active' => true,
        ]);
        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(28),
            'ends_at' => now()->addDays(2),
            'auto_renew' => false,
        ]);
    }

    public function test_the_renewal_bill_carries_its_period_key_and_repeated_runs_raise_one_bill(): void
    {
        $this->artisan('monetization:process-renewals')->assertSuccessful();
        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $bills = SellerInvoice::where('subscription_id', $this->subscription->id)->get();
        $this->assertCount(1, $bills);
        $this->assertSame(
            SellerInvoice::renewalPeriodKey($this->subscription->id, $this->subscription->ends_at->toDateString()),
            $bills->first()->billing_period_key,
        );
    }

    public function test_a_period_already_paid_is_not_billed_again(): void
    {
        SellerInvoice::create([
            'invoice_number' => 'SINV-PAID-PERIOD',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => SellerInvoice::STATUS_PAID,
            'paid_at' => now(),
            'due_date' => $this->subscription->ends_at->toDateString(),
            'billing_period_key' => SellerInvoice::renewalPeriodKey($this->subscription->id, $this->subscription->ends_at->toDateString()),
        ]);

        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $this->assertSame(1, SellerInvoice::where('subscription_id', $this->subscription->id)->count());
    }

    public function test_the_database_refuses_a_second_bill_for_the_same_period(): void
    {
        $key = SellerInvoice::renewalPeriodKey($this->subscription->id, '2026-12-01');
        $bill = fn (string $number): SellerInvoice => SellerInvoice::create([
            'invoice_number' => $number,
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => '2026-12-01',
            'billing_period_key' => $key,
        ]);

        $bill('SINV-DUP-1');

        $this->expectException(UniqueConstraintViolationException::class);
        $bill('SINV-DUP-2');
    }

    public function test_a_plan_change_bill_is_keyed_to_its_new_subscription(): void
    {
        $pro = SellerPlan::create(['name' => 'Pro', 'slug' => 'pro-period', 'subscription_price' => 999.00, 'billing_cycle_days' => 30, 'is_active' => true]);

        $invoice = app(SellerInvoiceSettlementService::class)->startPlanChange($this->seller, $pro->id);

        $this->assertSame(SellerInvoice::planChangeKey($invoice->subscription_id), $invoice->billing_period_key);
    }
}
