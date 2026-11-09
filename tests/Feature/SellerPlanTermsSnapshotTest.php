<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\SellerPlans\Pages\EditSellerPlan;
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
use App\Services\Monetization\MonetizationEngineService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MON-2: a seller keeps the plan terms they subscribed on until their next renewal.
 */
class SellerPlanTermsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerPlan $plan;

    private SellerSubscription $subscription;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        Queue::fake();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
            'phone' => '01711223344',
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Jamuna Garments',
            'store_slug' => 'jamuna-garments',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'is_subscription_fee_enabled' => true,
            'subscription_price' => 499.00,
            'billing_cycle_days' => 30,
            'is_listing_limit_enabled' => true,
            'max_active_products' => 75,
            'is_commission_enabled' => true,
            'retail_commission_rate' => 7.50,
            'wholesale_commission_rate' => 3.00,
            'is_active' => true,
        ]);

        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => SellerSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(28),
            'ends_at' => now()->addDays(2),
            'auto_renew' => true,
        ]);

        $retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $this->product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => Category::create(['name' => 'Garments', 'slug' => 'garments-mon2'])->id,
            'sales_channel_id' => $retail->id,
            'unit_id' => Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs'])->id,
            'title' => 'Men Oxford Shirt',
            'slug' => 'men-oxford-shirt-mon2',
            'is_active' => false,
        ]);
    }

    private function raisePlanTerms(): void
    {
        $this->plan->update([
            'subscription_price' => 799.00,
            'max_active_products' => 40,
            'retail_commission_rate' => 12.00,
        ]);
    }

    private function retailRate(): float
    {
        return app(MonetizationEngineService::class)->resolveCommissionRate(
            $this->seller,
            $this->product->fresh(),
            SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail(),
        );
    }

    public function test_a_new_subscription_keeps_the_plan_terms_it_was_sold_on(): void
    {
        $subscription = $this->subscription->fresh();

        $this->assertNotNull($subscription->terms_captured_at);
        $this->assertSame(499.0, $subscription->termSubscriptionPrice());
        $this->assertSame(75, $subscription->termMaxActiveProducts());
        $this->assertSame(7.5, $subscription->termCommissionRate(false));
    }

    public function test_editing_a_plan_does_not_change_a_running_subscription(): void
    {
        $this->raisePlanTerms();

        $this->assertSame(7.5, $this->retailRate());
        $this->assertSame(75, app(MonetizationEngineService::class)->getAllowedProductCount($this->seller));
    }

    public function test_the_renewal_bill_and_the_next_period_use_the_new_terms(): void
    {
        SellerWallet::create(['seller_id' => $this->seller->id, 'available_balance' => 1000.00]);
        $this->raisePlanTerms();

        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $invoice = SellerInvoice::where('subscription_id', $this->subscription->id)->latest('id')->firstOrFail();
        $this->assertSame(799.0, (float) $invoice->total_amount);
        $this->assertSame(SellerInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame(12.0, $this->retailRate());
        $this->assertSame(40, app(MonetizationEngineService::class)->getAllowedProductCount($this->seller));
    }

    public function test_moving_a_subscription_to_another_plan_takes_that_plans_terms(): void
    {
        $pro = SellerPlan::create([
            'name' => 'Pro Factory',
            'slug' => 'pro-factory',
            'subscription_price' => 1499.00,
            'is_listing_limit_enabled' => true,
            'max_active_products' => 300,
            'is_commission_enabled' => true,
            'retail_commission_rate' => 4.00,
            'wholesale_commission_rate' => 1.50,
            'is_active' => true,
        ]);

        $this->subscription->update(['plan_id' => $pro->id]);

        $this->assertSame(4.0, $this->retailRate());
        $this->assertSame(300, app(MonetizationEngineService::class)->getAllowedProductCount($this->seller));
    }

    public function test_an_admin_custom_rate_still_wins_over_the_kept_terms(): void
    {
        $this->subscription->update(['custom_retail_commission' => 5.00, 'custom_product_limit' => 200]);

        $this->assertSame(5.0, $this->retailRate());
        $this->assertSame(200, app(MonetizationEngineService::class)->getAllowedProductCount($this->seller));
    }

    public function test_changing_plan_terms_tells_each_seller_by_sms(): void
    {
        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
        ]));

        Livewire::test(EditSellerPlan::class, ['record' => $this->plan->getRouteKey()])
            ->fillForm(['subscription_price' => 799.00])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('New terms apply from each seller\'s next renewal');

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => str_contains(serialize($job), 'plan_terms_changed'));
        $this->assertSame(499.0, $this->subscription->fresh()->termSubscriptionPrice());
    }

    public function test_renaming_a_plan_sends_no_notice(): void
    {
        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
        ]));

        Livewire::test(EditSellerPlan::class, ['record' => $this->plan->getRouteKey()])
            ->fillForm(['name' => 'Growth Merchant Plus'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(SendSmsJob::class);
    }
}
