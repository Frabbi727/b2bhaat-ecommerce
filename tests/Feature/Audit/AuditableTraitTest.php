<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\StoreSetting;
use App\Models\SubOrder;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditableTraitTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected SalesChannel $retailChannel;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General', 'is_active' => true]
        );
        $this->retailChannel = SalesChannel::firstOrCreate(
            ['slug' => SalesChannel::RETAIL],
            ['name' => 'Retail', 'is_active' => true]
        );
        $this->unit = Unit::firstOrCreate(
            ['symbol' => 'pc'],
            ['name' => 'Piece', 'symbol' => 'pc']
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $this->unit->id,
            'slug' => 'product-'.uniqid(),
            'title' => 'Sample Product',
            'regular_price' => 100.00,
            'is_active' => true,
        ], $attributes));
    }

    public function test_product_lifecycle_automatically_logs_diffs_and_seller_id(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $this->actingAs($seller);

        // Created
        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Initial Title',
            'regular_price' => 100.00,
            'is_active' => true,
        ]);

        $createLog = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($createLog);
        $this->assertEquals($seller->id, $createLog->seller_id);
        $this->assertEquals('products', $createLog->module);
        $this->assertEquals('Initial Title', $createLog->record_label);

        // Updated
        $product->update([
            'title' => 'Updated Title',
            'regular_price' => 150.00,
        ]);

        $updateLog = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($updateLog);
        $this->assertEquals('Initial Title', $updateLog->old_values['title']);
        $this->assertEquals('Updated Title', $updateLog->new_values['title']);
        $this->assertContains('title', $updateLog->changed_fields);
        $this->assertContains('regular_price', $updateLog->changed_fields);

        // Deleted
        $product->delete();

        $deleteLog = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($deleteLog);
    }

    public function test_product_price_update_resolves_seller_id_via_product(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Product For Price',
            'regular_price' => 100.00,
        ]);

        $price = ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $this->retailChannel->id,
            'min_quantity' => 10,
            'max_quantity' => 50,
            'unit_price' => 85.00,
        ]);

        $priceLog = AuditLog::where('auditable_type', ProductPrice::class)
            ->where('auditable_id', $price->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($priceLog);
        $this->assertEquals($seller->id, $priceLog->seller_id);
    }

    public function test_product_variation_resolves_seller_id_via_parent_product(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Product For Variation',
            'regular_price' => 120.00,
        ]);

        $variation = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'TEST-VAR-99',
            'stock_quantity' => 25,
            'buy_price' => 60.00,
            'is_active' => true,
        ]);

        $variationLog = AuditLog::where('auditable_type', ProductVariation::class)
            ->where('auditable_id', $variation->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($variationLog);
        $this->assertSame($seller->id, $variationLog->seller_id);
        $this->assertSame('products', $variationLog->module);
        $this->assertSame('TEST-VAR-99', $variationLog->record_label);
    }

    public function test_refund_resolves_seller_id_via_sub_order(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $orderStatus = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $fulfillmentStatus = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'customer_id' => $seller->id,
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 500.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'SUB-'.uniqid(),
            'fulfillment_status_id' => $fulfillmentStatus->id,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
        ]);

        $refund = Refund::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder->id,
            'amount' => 100.00,
            'reason' => 'Defective piece',
            'status' => 'pending',
            'method' => 'bkash',
        ]);

        $refundLog = AuditLog::where('auditable_type', Refund::class)
            ->where('auditable_id', $refund->id)
            ->where('action', 'refund_created')
            ->first();

        $this->assertNotNull($refundLog);
        $this->assertSame($seller->id, $refundLog->seller_id);
        $this->assertSame('finance', $refundLog->module);
    }

    public function test_ignored_attributes_do_not_trigger_updated_audit_log(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Product For Ignore Test',
            'regular_price' => 100.00,
        ]);

        $initialUpdateCount = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'updated')
            ->count();

        $this->assertSame(0, $initialUpdateCount);

        // Updating only ignored attributes (views_count, sales_count)
        $product->forceFill([
            'views_count' => 99,
            'sales_count' => 12,
        ])->save();

        $afterIgnoredUpdateCount = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'updated')
            ->count();

        $this->assertSame(0, $afterIgnoredUpdateCount);

        // Now update a business attribute
        $product->update([
            'title' => 'Changed Title',
        ]);

        $afterRealUpdateCount = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'updated')
            ->count();

        $this->assertSame(1, $afterRealUpdateCount);
    }

    public function test_seller_profile_resolves_seller_id_and_sellers_module(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Super Factory',
            'store_slug' => 'super-factory',
            'commission_rate_percentage' => 5.0,
        ]);

        $log = AuditLog::where('auditable_type', SellerProfile::class)
            ->where('auditable_id', $profile->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('sellers', $log->module);
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertSame('Super Factory', $log->record_label);
    }

    public function test_store_setting_audit_module_and_null_seller_id(): void
    {
        $setting = StoreSetting::create([
            'key' => 'vat_percentage',
            'value' => '15',
            'group' => 'tax',
            'type' => 'int',
            'is_public' => true,
        ]);

        $log = AuditLog::where('auditable_type', StoreSetting::class)
            ->where('auditable_id', $setting->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('settings', $log->module);
        $this->assertNull($log->seller_id);
        $this->assertSame('vat_percentage', $log->record_label);
    }
}
