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
use App\Services\Monetization\MonetizationSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerSuspensionAndRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerProfile $profile;

    protected Category $category;

    protected Unit $unit;

    protected SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Bengal Leather Crafts',
            'store_slug' => 'bengal-leather',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->category = Category::create(['name' => 'Bags', 'slug' => 'bags']);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->channel = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $plan = SellerPlan::create([
            'name' => 'Growth Plan',
            'slug' => 'growth',
            'max_active_products' => 100,
            'is_listing_limit_enabled' => true,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
    }

    public function test_suspend_seller_snapshots_active_products_and_hides_storefront_catalog(): void
    {
        // Create 3 active products and 2 inactive products
        $activeProducts = [];
        for ($i = 1; $i <= 3; $i++) {
            $activeProducts[] = Product::create([
                'seller_id' => $this->seller->id,
                'category_id' => $this->category->id,
                'sales_channel_id' => $this->channel->id,
                'unit_id' => $this->unit->id,
                'title' => "Active Bag {$i}",
                'slug' => "active-bag-{$i}",
                'regular_price' => 1200.00,
                'is_active' => true,
            ]);
            $this->approveProducts(end($activeProducts));
        }

        $inactiveProduct = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'title' => 'Draft Inactive Bag',
            'slug' => 'draft-inactive-bag',
            'regular_price' => 1500.00,
            'is_active' => false,
        ]);
        $this->approveProducts($inactiveProduct);

        $this->assertEquals(3, Product::sellable()->where('seller_id', $this->seller->id)->count());

        $suspensionService = app(MonetizationSuspensionService::class);
        $event = $suspensionService->suspendSeller($this->seller, 'Delinquent subscription payment', null);

        // Storefront isolation: zero sellable products
        $this->assertEquals(0, Product::sellable()->where('seller_id', $this->seller->id)->count());
        $this->assertEquals('suspended', $this->profile->fresh()->monetization_status);

        // Suspension event tracks exact snapshot of previously active IDs
        $expectedActiveIds = collect($activeProducts)->pluck('id')->sort()->values()->toArray();
        $this->assertEquals($expectedActiveIds, collect($event->snapshot_active_product_ids)->sort()->values()->toArray());

        // Products table state
        foreach ($activeProducts as $p) {
            $this->assertTrue((bool) $p->fresh()->was_active_before_suspension);
            $this->assertFalse((bool) $p->fresh()->is_active);
        }
        $this->assertFalse((bool) $inactiveProduct->fresh()->was_active_before_suspension);
        $this->assertFalse((bool) $inactiveProduct->fresh()->is_active);
    }

    public function test_safe_selective_restoration_only_reactivates_previously_active_unmoderated_products(): void
    {
        // Product 1: Active before suspension
        $p1 = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'title' => 'Leather Wallet',
            'slug' => 'leather-wallet',
            'regular_price' => 500.00,
            'is_active' => true,
        ]);
        $this->approveProducts($p1);

        // Product 2: Active before suspension, but blocked by admin moderation
        $p2 = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'title' => 'Counterfeit Branded Wallet',
            'slug' => 'counterfeit-wallet',
            'regular_price' => 500.00,
            'is_active' => true,
            'blocked_at' => now()->subDay(),
            'blocked_reason' => 'Trademark violation',
        ]);
        $this->approveProducts($p2);

        // Product 3: Inactive before suspension (seller turned it off)
        $p3 = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'title' => 'Out of Season Belt',
            'slug' => 'out-of-season-belt',
            'regular_price' => 300.00,
            'is_active' => false,
        ]);
        $this->approveProducts($p3);

        $suspensionService = app(MonetizationSuspensionService::class);
        $suspensionService->suspendSeller($this->seller, 'Overdue payment', null);

        // Later, seller pays invoice and admin triggers restoration
        $restoredIds = $suspensionService->restoreSeller($this->seller);

        // P1 should be restored
        $this->assertTrue((bool) $p1->fresh()->is_active);
        $this->assertContains($p1->id, $restoredIds);

        // P2 had blocked_at set by admin -> MUST NOT be active
        $this->assertFalse((bool) $p2->fresh()->is_active);
        $this->assertNotContains($p2->id, $restoredIds);

        // P3 was inactive before suspension -> MUST remain inactive
        $this->assertFalse((bool) $p3->fresh()->is_active);
        $this->assertNotContains($p3->id, $restoredIds);

        $this->assertEquals('active', $this->profile->fresh()->monetization_status);
        $this->assertEquals(1, Product::sellable()->where('seller_id', $this->seller->id)->count());
    }
}
