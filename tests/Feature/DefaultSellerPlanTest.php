<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Kyc\ApplicationReviewService;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\MonetizationSuspensionService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * MON-3: every approved seller is on a plan, and a seller without a running plan gets the free plan's limit.
 */
class DefaultSellerPlanTest extends TestCase
{
    use RefreshDatabase;

    private SellerPlan $freePlan;

    private int $productCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        Queue::fake();

        $this->freePlan = SellerPlan::create([
            'name' => 'Free Starter',
            'slug' => 'free-mon3',
            'is_subscription_fee_enabled' => false,
            'subscription_price' => 0.00,
            'billing_cycle_days' => 365,
            'is_listing_limit_enabled' => true,
            'max_active_products' => 3,
            'is_commission_enabled' => true,
            'is_default_free_plan' => true,
            'is_active' => true,
        ]);
    }

    private function seller(bool $approved = true): User
    {
        $seller = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Store '.$seller->id,
            'store_slug' => 'store-'.$seller->id,
            'monetization_status' => 'active',
            'is_approved' => $approved,
        ]);

        return $seller;
    }

    private function showProducts(User $seller, int $count): void
    {
        $channel = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $category = Category::firstOrCreate(['slug' => 'mon3'], ['name' => 'MON-3']);
        $unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);

        for ($i = 0; $i < $count; $i++) {
            $this->productCounter++;
            Product::create([
                'seller_id' => $seller->id,
                'category_id' => $category->id,
                'sales_channel_id' => $channel->id,
                'unit_id' => $unit->id,
                'title' => "Product {$this->productCounter}",
                'slug' => "mon3-product-{$this->productCounter}",
                'is_active' => true,
            ]);
        }
    }

    public function test_approving_a_seller_puts_them_on_the_default_plan(): void
    {
        $seller = $this->seller(approved: false);

        app(ApplicationReviewService::class)->approveSeller($seller->sellerProfile, 10.00);
        app(ApplicationReviewService::class)->approveSeller($seller->sellerProfile->fresh(), 10.00);

        $subscriptions = SellerSubscription::where('seller_id', $seller->id)->get();
        $this->assertCount(1, $subscriptions);
        $this->assertSame($this->freePlan->id, $subscriptions->first()->plan_id);
        $this->assertSame(SellerSubscription::STATUS_ACTIVE, $subscriptions->first()->status);
        $this->assertSame(3, app(MonetizationEngineService::class)->getAllowedProductCount($seller));
    }

    public function test_approval_keeps_a_plan_the_seller_already_has(): void
    {
        $seller = $this->seller(approved: false);
        $growth = SellerPlan::create(['name' => 'Growth', 'slug' => 'growth-mon3', 'max_active_products' => 75, 'is_listing_limit_enabled' => true, 'is_active' => true]);
        SellerSubscription::create(['seller_id' => $seller->id, 'plan_id' => $growth->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30)]);

        app(ApplicationReviewService::class)->approveSeller($seller->sellerProfile, 10.00);

        $this->assertSame(1, SellerSubscription::where('seller_id', $seller->id)->count());
        $this->assertSame(75, app(MonetizationEngineService::class)->getAllowedProductCount($seller));
    }

    public function test_a_seller_without_a_plan_gets_the_free_limit_and_keeps_visible_products(): void
    {
        $seller = $this->seller();
        $this->showProducts($seller, 5);

        $engine = app(MonetizationEngineService::class);

        $this->assertSame(3, $engine->getAllowedProductCount($seller));
        $this->assertFalse($engine->canActivateMoreProducts($seller));
        $this->assertSame(5, Product::where('seller_id', $seller->id)->where('is_active', true)->count());
    }

    public function test_an_ended_plan_falls_back_to_the_free_limit(): void
    {
        $seller = $this->seller();
        $growth = SellerPlan::create(['name' => 'Growth', 'slug' => 'growth-ended', 'max_active_products' => 75, 'is_listing_limit_enabled' => true, 'is_active' => true]);
        SellerSubscription::create(['seller_id' => $seller->id, 'plan_id' => $growth->id, 'status' => 'expired', 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);

        $this->assertSame(3, app(MonetizationEngineService::class)->getAllowedProductCount($seller));
    }

    public function test_without_a_default_plan_nothing_changes(): void
    {
        $this->freePlan->update(['is_default_free_plan' => false]);
        $seller = $this->seller(approved: false);

        app(ApplicationReviewService::class)->approveSeller($seller->sellerProfile, 10.00);

        $this->assertSame(0, SellerSubscription::where('seller_id', $seller->id)->count());
        $this->assertNull(app(MonetizationEngineService::class)->getAllowedProductCount($seller));
    }

    public function test_the_backfill_assigns_only_approved_sellers_without_a_plan(): void
    {
        $withoutPlan = $this->seller();
        $pending = $this->seller(approved: false);
        $onPlan = $this->seller();
        SellerSubscription::create(['seller_id' => $onPlan->id, 'plan_id' => $this->freePlan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(365)]);

        $this->artisan('monetization:assign-default-plan', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SellerSubscription::where('seller_id', $withoutPlan->id)->count());

        $this->artisan('monetization:assign-default-plan')->assertSuccessful()->expectsOutputToContain('1 seller(s) put on Free Starter');
        $this->artisan('monetization:assign-default-plan')->assertSuccessful()->expectsOutputToContain('0 seller(s)');

        $this->assertSame(1, SellerSubscription::where('seller_id', $withoutPlan->id)->count());
        $this->assertSame(0, SellerSubscription::where('seller_id', $pending->id)->count());
        $this->assertSame(1, SellerSubscription::where('seller_id', $onPlan->id)->count());
    }

    public function test_restoring_a_suspended_store_uses_its_own_plan_limit(): void
    {
        $seller = $this->seller();
        $growth = SellerPlan::create(['name' => 'Growth', 'slug' => 'growth-restore', 'max_active_products' => 75, 'is_listing_limit_enabled' => true, 'is_active' => true]);
        SellerSubscription::create(['seller_id' => $seller->id, 'plan_id' => $growth->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29)]);
        $this->showProducts($seller, 5);

        $suspension = app(MonetizationSuspensionService::class);
        $suspension->suspendSeller($seller, 'policy');
        $restored = $suspension->restoreSeller($seller);

        $this->assertCount(5, $restored);
    }
}
