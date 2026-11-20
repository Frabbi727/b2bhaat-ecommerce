<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->approveProducts($product2);
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

    /**
     * WA-CHK-02 last line of defence: the splitter never writes a line that would lower the order total.
     *
     * @return array<string, array{int, float}>
     */
    public static function invalidLineProvider(): array
    {
        return [
            'zero quantity' => [0, 250.00],
            'negative quantity' => [-5, 250.00],
            'negative unit price' => [2, -1.00],
        ];
    }

    #[DataProvider('invalidLineProvider')]
    public function test_order_with_an_invalid_line_is_refused_and_nothing_is_written(int $quantity, float $unitPrice): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('seller_id', User::where('email', 'seller@nailsfactory.com')->value('id'))->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $stockBefore = (int) $variation->stock_quantity;
        $ordersBefore = Order::count();

        try {
            app(OrderSplitterService::class)->createAndSplitOrder($customer, [
                'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
                'shipping_address' => ['recipient_name' => 'Tanvir Hasan', 'street' => 'Dhanmondi, Dhaka', 'phone' => '+8801700000004'],
                'items' => [[
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]],
            ]);
            $this->fail('The order was placed.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Remove it from your cart', $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($stockBefore, (int) $variation->fresh()->stock_quantity);
    }

    /**
     * WA-PRC-03 backstop: a line on a product with options must name the option, or the seller can't ship it and no
     * stock is taken.
     */
    public function test_line_without_an_option_on_a_product_with_options_is_refused(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->assertTrue((bool) $product->has_variations);
        $ordersBefore = Order::count();

        try {
            app(OrderSplitterService::class)->createAndSplitOrder($customer, [
                'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
                'shipping_address' => ['recipient_name' => 'Tanvir Hasan', 'street' => 'Dhanmondi, Dhaka', 'phone' => '+8801700000004'],
                'items' => [[
                    'product_id' => $product->id,
                    'variation_id' => null,
                    'sales_channel_id' => SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id'),
                    'quantity' => 60,
                    'unit_price' => 100.00,
                ]],
            ]);
            $this->fail('A line without an option was ordered.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('choose an option', $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_each_order_line_keeps_the_sellers_cost_per_unit_at_the_time_of_ordering(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        $product = Product::where('seller_id', $seller->id)->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $variation->update(['buy_price' => 77.50, 'stock_quantity' => 50]);

        $order = app(OrderSplitterService::class)->createAndSplitOrder($customer, [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'shipping_address' => ['recipient_name' => 'Tanvir Hasan', 'street' => 'Dhanmondi, Dhaka', 'phone' => '+8801700000004'],
            'items' => [[
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'sales_channel_id' => $retailChannel->id,
                'quantity' => 2,
                'unit_price' => 250.00,
            ]],
        ]);

        $line = $order->subOrders->first()->items->first();
        $this->assertSame('77.50', $line->unit_cost);

        // A later cost change does not rewrite the profit of an order already placed
        $variation->update(['buy_price' => 90]);
        $this->assertSame('77.50', $line->fresh()->unit_cost);
    }

    /**
     * Roadmap 4.5: a seller's commission rate is looked up once per order, not once per line.
     */
    public function test_commission_rate_is_looked_up_once_per_seller_and_channel(): void
    {
        StoreSetting::setTypedValue('monetization_enabled', true, 'monetization', 'boolean');
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $retail = SalesChannel::where('slug', SalesChannel::RETAIL)->value('id');
        $variations = $product->variations()->where('is_active', true)->take(3)->get();
        $this->assertGreaterThan(1, $variations->count());
        $unitPrice = (float) $product->prices()->where('sales_channel_id', $retail)->orderBy('min_quantity')->value('unit_price');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $order = app(OrderSplitterService::class)->createAndSplitOrder($customer, [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'shipping_address' => ['recipient_name' => 'Tanvir Hasan', 'street' => 'Dhanmondi, Dhaka', 'phone' => '+8801700000004'],
            'items' => $variations->map(fn (ProductVariation $variation): array => [
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'sales_channel_id' => $retail,
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ])->all(),
        ]);
        $subscriptionLookups = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "seller_subscriptions"'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $subscriptionLookups);
        $this->assertCount(1, $order->items()->pluck('commission_rate')->unique());
    }
}
