<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonetizationEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerProfile $profile;

    protected SalesChannel $retailChannel;

    protected SalesChannel $wholesaleChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Beximco Apparels',
            'store_slug' => 'beximco-apparels',
            'commission_rate_percentage' => 10.00,
            'is_approved' => true,
        ]);

        $this->retailChannel = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $this->wholesaleChannel = SalesChannel::firstOrCreate(['slug' => SalesChannel::WHOLESALE], ['name' => 'Wholesale', 'is_active' => true]);
    }

    public function test_resolves_commission_from_active_plan(): void
    {
        $plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'retail_commission_rate' => 7.50,
            'wholesale_commission_rate' => 3.00,
            'is_commission_enabled' => true,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $category = Category::create(['name' => 'Garments', 'slug' => 'garments']);
        $unit = Unit::firstOrCreate(['symbol' => 'pcs'], ['name' => 'Pieces']);
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Men Oxford Shirt',
            'slug' => 'men-oxford-shirt',
            'is_active' => true,
        ]);

        $engine = app(MonetizationEngineService::class);

        $retailRate = $engine->resolveCommissionRate($this->seller, $product, $this->retailChannel);
        $wholesaleRate = $engine->resolveCommissionRate($this->seller, $product, $this->wholesaleChannel);

        $this->assertEquals(7.50, $retailRate);
        $this->assertEquals(3.00, $wholesaleRate);
    }

    public function test_custom_subscription_override_takes_highest_precedence(): void
    {
        $plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'retail_commission_rate' => 7.50,
            'wholesale_commission_rate' => 3.00,
            'is_commission_enabled' => true,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
            'custom_retail_commission' => 5.00,
            'custom_wholesale_commission' => 1.50,
        ]);

        $category = Category::create(['name' => 'Fabrics', 'slug' => 'fabrics']);
        $unit = Unit::firstOrCreate(['symbol' => 'pcs'], ['name' => 'Pieces']);
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Cotton Fabric Roll',
            'slug' => 'cotton-fabric-roll',
            'is_active' => true,
        ]);

        $engine = app(MonetizationEngineService::class);

        $this->assertEquals(5.00, $engine->resolveCommissionRate($this->seller, $product, $this->retailChannel));
        $this->assertEquals(1.50, $engine->resolveCommissionRate($this->seller, $product, $this->wholesaleChannel));
    }

    public function test_commission_returns_zero_when_plan_disables_commission(): void
    {
        $plan = SellerPlan::create([
            'name' => 'Pure Subscription Plan',
            'slug' => 'pure-sub',
            'is_commission_enabled' => false,
            'retail_commission_rate' => 10.00,
            'wholesale_commission_rate' => 4.00,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $category = Category::create(['name' => 'Yarn', 'slug' => 'yarn']);
        $unit = Unit::firstOrCreate(['symbol' => 'pcs'], ['name' => 'Pieces']);
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Polyester Yarn Cone',
            'slug' => 'polyester-yarn-cone',
            'is_active' => true,
        ]);

        $engine = app(MonetizationEngineService::class);

        $this->assertEquals(0.00, $engine->resolveCommissionRate($this->seller, $product, $this->retailChannel));
        $this->assertEquals(0.00, $engine->resolveCommissionRate($this->seller, $product, $this->wholesaleChannel));
    }

    public function test_listing_quota_evaluation(): void
    {
        $plan = SellerPlan::create([
            'name' => 'Starter 2 Items Plan',
            'slug' => 'starter-2',
            'is_listing_limit_enabled' => true,
            'max_active_products' => 2,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $category = Category::create(['name' => 'Leather', 'slug' => 'leather']);
        $unit = Unit::firstOrCreate(['symbol' => 'pcs'], ['name' => 'Pieces']);
        $engine = app(MonetizationEngineService::class);

        $this->assertTrue($engine->canActivateMoreProducts($this->seller));

        Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Product 1',
            'slug' => 'product-1',
            'is_active' => true,
        ]);

        $this->assertTrue($engine->canActivateMoreProducts($this->seller));

        Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Product 2',
            'slug' => 'product-2',
            'is_active' => true,
        ]);

        // Reached 2 of 2 limit
        $this->assertFalse($engine->canActivateMoreProducts($this->seller));

        // Inactive product does not count against quota
        Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Draft Product 3',
            'slug' => 'draft-product-3',
            'is_active' => false,
        ]);

        $this->assertFalse($engine->canActivateMoreProducts($this->seller));
    }

    public function test_order_splitter_calculates_commission_using_monetization_engine(): void
    {
        $plan = SellerPlan::create([
            'name' => 'Factory Pro',
            'slug' => 'factory_pro',
            'retail_commission_rate' => 5.00,
            'wholesale_commission_rate' => 2.00,
            'is_commission_enabled' => true,
        ]);

        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $category = Category::create(['name' => 'Denim', 'slug' => 'denim']);
        $unit = Unit::firstOrCreate(['symbol' => 'pcs'], ['name' => 'Pieces']);
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->wholesaleChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Wholesale Denim Jeans (100 Pcs Lot)',
            'slug' => 'wholesale-denim-jeans',
            'regular_price' => 500.00,
            'is_active' => true,
        ]);
        $this->approveProducts($product);

        $variation = $product->ensureDefaultVariation();
        $variation->update(['stock_quantity' => 500]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $this->wholesaleChannel->id,
            'min_quantity' => 10,
            'unit_price' => 500.00,
        ]);

        $customer = User::factory()->create();
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash on Delivery', 'is_active' => true]);

        $orderSplitter = app(OrderSplitterService::class);
        $order = $orderSplitter->createAndSplitOrder($customer, [
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => [
                'delivery_zone' => 'inside_dhaka',
                'address' => 'Mirpur 10, Dhaka',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'sales_channel_id' => $this->wholesaleChannel->id,
                    'quantity' => 20,
                    'unit_price' => 500.00,
                ],
            ],
        ]);

        $subOrder = $order->subOrders()->where('seller_id', $this->seller->id)->first();
        $this->assertNotNull($subOrder);
        // Subtotal: 20 * 500 = 10,000. Plan wholesale rate: 2%. Commission = 10,000 * 0.02 = 200.00.
        $this->assertEquals(10000.00, (float) $subOrder->subtotal);
        $this->assertEquals(200.00, (float) $subOrder->commission_amount);

        // MON-13: the line keeps the rate it was charged, whatever the plan says later
        $this->assertSame('2.00', $subOrder->items()->value('commission_rate'));
    }
}
