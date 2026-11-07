<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\StoreSetting;
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
        $product->update([
            'views_count' => 99,
            'sales_count' => 12,
        ]);

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
    }
}
