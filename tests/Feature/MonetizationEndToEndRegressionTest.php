<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\SellerPlanSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonetizationEndToEndRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_seller_monetization_end_to_end_lifecycle(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(SellerPlanSeeder::class);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
            'name' => 'Kaiser Ahmed',
            'phone' => '01819998877',
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Kaiser Export Fabrics',
            'store_slug' => 'kaiser-fabrics',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $category = Category::create(['name' => 'Silk', 'slug' => 'silk']);
        $unit = Unit::firstOrCreate(['symbol' => 'pc'], ['name' => 'Piece', 'symbol' => 'pc']);
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $wholesaleChannel = SalesChannel::where('slug', SalesChannel::WHOLESALE)->firstOrFail();

        $engine = app(MonetizationEngineService::class);
        $suspensionService = app(MonetizationSuspensionService::class);

        // 1. Assign Free Plan
        $freePlan = SellerPlan::where('slug', 'free')->firstOrFail();
        $sub = SellerSubscription::create([
            'seller_id' => $seller->id,
            'plan_id' => $freePlan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(364),
        ]);

        $this->assertEquals(15, $engine->getAllowedProductCount($seller));

        // 2. Add 2 active products and 1 draft product
        $p1 = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Rajshahi Pure Silk Saree',
            'slug' => 'rajshahi-silk-saree',
            'regular_price' => 4500.00,
            'is_active' => true,
        ]);
        $v1 = $p1->ensureDefaultVariation();
        $v1->update(['stock_quantity' => 50]);
        ProductPrice::create([
            'product_id' => $p1->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_price' => 4500.00,
            'min_quantity' => 1,
        ]);

        $p2 = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Silk Scarf',
            'slug' => 'silk-scarf',
            'regular_price' => 800.00,
            'is_active' => true,
        ]);
        $v2 = $p2->ensureDefaultVariation();
        $v2->update(['stock_quantity' => 100]);
        ProductPrice::create([
            'product_id' => $p2->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_price' => 800.00,
            'min_quantity' => 1,
        ]);

        $pDraft = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Unfinished Silk Shawl',
            'slug' => 'unfinished-silk-shawl',
            'regular_price' => 1200.00,
            'is_active' => false,
        ]);

        $this->assertEquals(2, Product::sellable()->where('seller_id', $seller->id)->count());

        // 3. Customer places retail order under Free Plan (Retail Commission: 10%)
        $customer = User::factory()->create();
        $cod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        $orderSplitter = app(OrderSplitterService::class);
        $order = $orderSplitter->createAndSplitOrder($customer, [
            'payment_method_id' => $cod->id,
            'shipping_address' => ['delivery_zone' => 'inside_dhaka', 'address' => 'Gulshan 2, Dhaka'],
            'items' => [
                [
                    'product_id' => $p1->id,
                    'variation_id' => $v1->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 2,
                    'unit_price' => 4500.00,
                ],
            ],
        ]);

        $subOrder = $order->subOrders()->where('seller_id', $seller->id)->firstOrFail();
        // Subtotal: 9,000. 10% commission on Free plan = 900.00
        $this->assertEquals(9000.00, (float) $subOrder->subtotal);
        $this->assertEquals(900.00, (float) $subOrder->commission_amount);

        // 4. Seller upgrades to Growth Plan (7.5% retail / 3% wholesale)
        $growthPlan = SellerPlan::where('slug', 'growth')->firstOrFail();
        $sub->update([
            'plan_id' => $growthPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        // Place a second order under Growth plan -> commission should now calculate at 7.50%
        $order2 = $orderSplitter->createAndSplitOrder($customer, [
            'payment_method_id' => $cod->id,
            'shipping_address' => ['delivery_zone' => 'inside_dhaka', 'address' => 'Banani, Dhaka'],
            'items' => [
                [
                    'product_id' => $p2->id,
                    'variation_id' => $v2->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 5,
                    'unit_price' => 800.00,
                ],
            ],
        ]);

        $subOrder2 = $order2->subOrders()->where('seller_id', $seller->id)->firstOrFail();
        // Subtotal: 4,000. 7.50% commission = 300.00
        $this->assertEquals(4000.00, (float) $subOrder2->subtotal);
        $this->assertEquals(300.00, (float) $subOrder2->commission_amount);

        // 5. Subscription expires and non-payment triggers suspension
        $sub->update([
            'status' => 'grace_period',
            'ends_at' => now()->subDays(5),
            'grace_period_ends_at' => now()->subHour(),
        ]);

        SellerInvoice::create([
            'invoice_number' => 'SINV-REGRESSION-001',
            'seller_id' => $seller->id,
            'subscription_id' => $sub->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'unpaid',
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->artisan('monetization:enforce-suspensions')->assertSuccessful();

        $this->assertEquals('suspended', $profile->fresh()->monetization_status);
        $this->assertEquals(0, Product::sellable()->where('seller_id', $seller->id)->count());

        // 6. Settle invoice and trigger safe selective restoration
        $restored = $suspensionService->restoreSeller($seller);

        // Exactly p1 and p2 should be restored; pDraft remains inactive!
        $this->assertContains($p1->id, $restored);
        $this->assertContains($p2->id, $restored);
        $this->assertNotContains($pDraft->id, $restored);

        $this->assertTrue((bool) $p1->fresh()->is_active);
        $this->assertTrue((bool) $p2->fresh()->is_active);
        $this->assertFalse((bool) $pDraft->fresh()->is_active);

        $this->assertEquals('active', $profile->fresh()->monetization_status);
        $this->assertEquals(2, Product::sellable()->where('seller_id', $seller->id)->count());
    }
}
