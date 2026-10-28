<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderSplitterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_multi_vendor_order_splits_into_distinct_sub_orders_per_seller(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller1 = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        // Create a second seller
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $bothChannel = SalesChannel::where('slug', SalesChannel::BOTH)->firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        $seller2 = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Second Vendor Ltd',
            'email' => 'seller2@vendor.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $seller2->id,
            'store_name' => 'Fashion Hub',
            'store_slug' => 'fashion-hub',
            'commission_rate_percentage' => 12.00,
            'is_approved' => true,
        ]);

        $product1 = Product::where('seller_id', $seller1->id)->firstOrFail();
        $variation1 = $product1->variations()->firstOrFail();

        // Create product for seller 2
        $product2 = Product::create([
            'seller_id' => $seller2->id,
            'category_id' => $product1->category_id,
            'sales_channel_id' => $bothChannel->id,
            'unit_id' => $product1->unit_id,
            'title' => 'Lipstick Gloss',
            'slug' => 'lipstick-gloss',
            'buy_price' => 30.00,
            'has_variations' => true,
            'is_active' => true,
        ]);
        $variation2 = ProductVariation::create([
            'product_id' => $product2->id,
            'sku' => 'LIP-RED-01',
            'buy_price' => 30.00,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $product2->id,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 150.00,
        ]);

        $paymentMethod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        $splitter = app(OrderSplitterService::class);
        $order = $splitter->createAndSplitOrder($customer, [
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => [
                'recipient_name' => 'Tanvir Hasan',
                'street' => 'Dhanmondi, Dhaka',
                'phone' => '+8801700000004',
            ],
            'items' => [
                [
                    'product_id' => $product1->id,
                    'variation_id' => $variation1->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 2,
                    'unit_price' => 250.00, // 500.00
                ],
                [
                    'product_id' => $product2->id,
                    'variation_id' => $variation2->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 3,
                    'unit_price' => 150.00, // 450.00
                ],
            ],
        ]);

        $this->assertNotNull($order->id);
        $this->assertEquals(950.00, (float) $order->total_amount);
        $this->assertCount(2, $order->subOrders);

        // Sub-order 1 (Seller 1)
        $subOrder1 = $order->subOrders->firstWhere('seller_id', $seller1->id);
        $this->assertNotNull($subOrder1);
        $this->assertEquals(500.00, (float) $subOrder1->subtotal);
        // Commission for seller 1 (10% of 500 = 50.00)
        $this->assertEquals(50.00, (float) $subOrder1->commission_amount);
        $this->assertEquals(450.00, $subOrder1->net_earnings);

        // Sub-order 2 (Seller 2)
        $subOrder2 = $order->subOrders->firstWhere('seller_id', $seller2->id);
        $this->assertNotNull($subOrder2);
        $this->assertEquals(450.00, (float) $subOrder2->subtotal);
        // Commission for seller 2 (12% of 450 = 54.00)
        $this->assertEquals(54.00, (float) $subOrder2->commission_amount);
        $this->assertEquals(396.00, $subOrder2->net_earnings);
    }
}
