<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonetizationCronAndSmsAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerProfile $profile;

    protected SellerPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);

        Queue::fake();

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
            'phone' => '01711223344',
            'name' => 'Monirul Islam',
        ]);

        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Jamuna Garments',
            'store_slug' => 'jamuna-garments',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'subscription_price' => 499.00,
            'billing_cycle_days' => 30,
            'max_active_products' => 75,
            'is_active' => true,
        ]);
    }

    public function test_process_renewals_command_generates_invoice_and_auto_renews_with_wallet(): void
    {
        SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 1000.00,
        ]);

        // Subscription expiring in 2 days
        $subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(28),
            'ends_at' => now()->addDays(2),
            'auto_renew' => true,
        ]);

        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $this->assertDatabaseHas('seller_invoices', [
            'seller_id' => $this->seller->id,
            'total_amount' => '499.00',
            'status' => 'paid',
        ]);

        // Wallet balance deducted: 1000 - 499 = 501
        $this->assertEquals(501.00, (float) $this->seller->sellerWallet->fresh()->available_balance);

        // Subscription extended by 30 days
        $this->assertTrue($subscription->fresh()->ends_at->isAfter(now()->addDays(25)));
        Queue::assertPushed(SendSmsJob::class);
    }

    public function test_check_grace_periods_command_transitions_overdue_subscriptions(): void
    {
        // Subscription expired yesterday with an unpaid invoice
        $subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDay(),
            'auto_renew' => false,
        ]);

        SellerInvoice::create([
            'invoice_number' => 'SINV-OVERDUE-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'unpaid',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('monetization:check-grace-periods')->assertSuccessful();

        $this->assertEquals('grace_period', $subscription->fresh()->status);
        $this->assertEquals('grace_period', $this->profile->fresh()->monetization_status);
        $this->assertNotNull($subscription->fresh()->grace_period_ends_at);
        Queue::assertPushed(SendSmsJob::class);
    }

    public function test_enforce_suspensions_command_suspends_delinquent_sellers(): void
    {
        $unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $channel = SalesChannel::firstOrCreate(['slug' => 'retail'], ['name' => 'Retail', 'is_active' => true]);
        $category = Category::firstOrCreate(['slug' => 'apparel'], ['name' => 'Apparel']);

        // Active product that should be hidden
        Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'title' => 'Jamuna Polo Shirt',
            'slug' => 'jamuna-polo-shirt',
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'regular_price' => 450.00,
            'is_active' => true,
        ]);

        // Subscription past its grace period
        $subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'grace_period',
            'starts_at' => now()->subDays(35),
            'ends_at' => now()->subDays(5),
            'grace_period_ends_at' => now()->subHour(),
        ]);

        SellerInvoice::create([
            'invoice_number' => 'SINV-SUSPEND-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'unpaid',
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->artisan('monetization:enforce-suspensions')->assertSuccessful();

        $this->assertEquals('suspended', $this->profile->fresh()->monetization_status);
        $this->assertEquals(0, Product::sellable()->where('seller_id', $this->seller->id)->count());
        $this->assertDatabaseHas('seller_suspension_events', [
            'seller_id' => $this->seller->id,
            'trigger_type' => 'automated_billing',
        ]);
        Queue::assertPushed(SendSmsJob::class);
    }
}
