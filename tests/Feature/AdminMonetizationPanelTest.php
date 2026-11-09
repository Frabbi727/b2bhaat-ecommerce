<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\SellerMonetization\Pages\ListSellerMonetizations;
use App\Filament\Admin\Resources\SellerPlans\Pages\CreateSellerPlan;
use App\Filament\Admin\Resources\SellerPlans\Pages\ListSellerPlans;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\User;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMonetizationPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $seller;

    protected SellerProfile $profile;

    protected SellerPlan $plan;

    protected SellerSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
            'name' => 'Zakir Hossain',
        ]);

        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Dhaka Plastic Industries',
            'store_slug' => 'dhaka-plastics',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'subscription_price' => 499.00,
            'max_active_products' => 75,
            'retail_commission_rate' => 7.50,
            'wholesale_commission_rate' => 3.00,
            'is_active' => true,
        ]);

        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'auto_renew' => true,
        ]);
    }

    public function test_admin_can_view_and_create_seller_plans(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerPlans::class)
            ->assertSuccessful()
            ->assertSee('Growth Merchant');

        Livewire::test(CreateSellerPlan::class)
            ->fillForm([
                'name' => 'VIP Factory Tier',
                'slug' => 'vip-factory',
                'is_subscription_fee_enabled' => true,
                'subscription_price' => 1999.00,
                'billing_cycle_days' => 30,
                'is_listing_limit_enabled' => true,
                'max_active_products' => 500,
                'is_commission_enabled' => true,
                'retail_commission_rate' => 4.00,
                'wholesale_commission_rate' => 1.50,
                'badge_label' => 'VIP Partner',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('seller_plans', [
            'slug' => 'vip-factory',
            'subscription_price' => '1999.00',
            'max_active_products' => 500,
        ]);
    }

    public function test_admin_can_view_seller_monetization_list_and_suspend_seller(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerMonetizations::class)
            ->assertSuccessful()
            ->assertSee('Dhaka Plastic Industries')
            ->callTableAction('suspend', $this->profile, [
                'reason' => 'Failure to pay subscription fee',
            ]);

        $this->assertEquals('suspended', $this->profile->fresh()->monetization_status);
        $this->assertDatabaseHas('seller_suspension_events', [
            'seller_id' => $this->seller->id,
            'trigger_type' => 'admin_manual',
            'reason' => 'Failure to pay subscription fee',
        ]);
    }

    public function test_admin_can_restore_suspended_seller(): void
    {
        $this->actingAs($this->admin);

        $this->profile->update(['monetization_status' => 'suspended']);

        Livewire::test(ListSellerMonetizations::class)
            ->callTableAction('restore', $this->profile);

        $this->assertEquals('active', $this->profile->fresh()->monetization_status);
    }

    public function test_admin_can_record_offline_payment(): void
    {
        $this->actingAs($this->admin);

        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-202611-TEST',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'unpaid',
            'due_date' => now()->toDateString(),
        ]);

        $this->profile->update(['monetization_status' => 'suspended']);

        Livewire::test(ListSellerMonetizations::class)
            ->callTableAction('recordOfflinePayment', $this->profile, [
                'payment_method' => 'bkash',
                'amount' => 499.00,
                'transaction_reference' => 'BKASH-MANUAL-001',
                'notes' => 'Received via personal bKash account',
            ]);

        $this->assertEquals('paid', $invoice->fresh()->status);
        $this->assertEquals('active', $this->profile->fresh()->monetization_status);
        $this->assertDatabaseHas('seller_payments', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'bkash',
            'transaction_reference' => 'BKASH-MANUAL-001',
            'status' => 'successful',
        ]);
    }

    /**
     * MON-1: a seller who pays during the grace period is back to normal, and gets the paid period.
     */
    public function test_offline_payment_during_grace_returns_the_store_to_active_and_renews(): void
    {
        $this->actingAs($this->admin);
        $this->subscription->update(['status' => 'grace_period', 'ends_at' => now()->subDay(), 'grace_period_ends_at' => now()->addDays(2)]);
        $this->profile->update(['monetization_status' => 'grace_period']);
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-GRACE-1',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'unpaid',
            'due_date' => now()->toDateString(),
        ]);

        Livewire::test(ListSellerMonetizations::class)
            ->callTableAction('recordOfflinePayment', $this->profile, [
                'payment_method' => 'nagad',
                'amount' => 499.00,
                'transaction_reference' => 'NAGAD-GRACE-1',
            ]);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $this->profile->fresh()->monetization_status);
        $subscription = $this->subscription->fresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertTrue($subscription->ends_at->greaterThan(now()->addDays(25)));
    }

    public function test_offline_payment_without_an_unpaid_bill_records_nothing(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerMonetizations::class)
            ->callTableAction('recordOfflinePayment', $this->profile, [
                'payment_method' => 'bkash',
                'amount' => 499.00,
                'transaction_reference' => 'BKASH-NOBILL',
            ]);

        $this->assertDatabaseMissing('seller_payments', ['transaction_reference' => 'BKASH-NOBILL']);
    }

    public function test_extend_grace_is_not_offered_for_a_seller_who_is_up_to_date(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerMonetizations::class)
            ->assertTableActionHidden('extendGrace', $this->profile);

        $this->assertSame('active', $this->profile->fresh()->monetization_status);
    }
}
