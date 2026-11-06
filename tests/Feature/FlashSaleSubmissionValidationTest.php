<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Exceptions\FlashSaleSubmissionException;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Promotions\FlashSaleSubmissionService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleSubmissionValidationTest extends TestCase
{
    use RefreshDatabase;

    private FlashSaleSubmissionService $service;

    private User $seller;

    private Category $category;

    private Product $product;

    private FlashSale $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);

        $this->service = new FlashSaleSubmissionService;
        $this->seller = User::factory()->create();

        $this->category = Category::create([
            'name' => 'Fashion',
            'slug' => 'fashion',
            'is_active' => true,
        ]);

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::firstOrFail();

        $this->product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Silk Scarf',
            'slug' => 'silk-scarf',
            'is_active' => true,
        ]);

        $this->product->ensureDefaultVariation()->update(['stock_quantity' => 100]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 1000.00,
        ]);

        $this->campaign = FlashSale::factory()->create([
            'name' => 'Mega Eid Sale',
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(3),
            'min_discount_percentage' => 10.00,
            'max_discount_percentage' => 50.00,
            'max_products_per_seller' => 3,
            'min_stock_per_product' => 5,
            'max_stock_per_product' => 50,
            'requires_approval' => true,
        ]);
    }

    public function test_rejects_submission_if_product_id_is_missing(): void
    {
        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Product ID is required.');

        $this->service->submit($this->seller, $this->campaign, [
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_product_belongs_to_another_seller(): void
    {
        $otherSeller = User::factory()->create();
        $otherProduct = Product::create([
            'seller_id' => $otherSeller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail()->id,
            'unit_id' => Unit::firstOrFail()->id,
            'title' => 'Other Seller Item',
            'slug' => 'other-seller-item',
            'is_active' => true,
        ]);
        $otherProduct->ensureDefaultVariation()->update(['stock_quantity' => 50]);
        ProductPrice::create([
            'product_id' => $otherProduct->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail()->id,
            'min_quantity' => 1,
            'unit_price' => 500.00,
        ]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('You can only submit your own products.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $otherProduct->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_registration_is_closed(): void
    {
        $this->campaign->update([
            'registration_starts_at' => now()->subDays(3),
            'registration_ends_at' => now()->subDay(),
        ]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Registration is closed for this campaign.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_product_category_not_in_whitelist(): void
    {
        $allowedCategory = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);
        $this->campaign->categories()->attach($allowedCategory);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('This product does not belong to an eligible campaign category.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_seller_reaches_max_products_quota(): void
    {
        $this->campaign->update(['max_products_per_seller' => 1]);

        FlashSaleProduct::create([
            'flash_sale_id' => $this->campaign->id,
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'original_retail_price' => 1000.00,
            'campaign_price' => 800.00,
            'stock_limit' => 10,
            'units_sold' => 0,
        ]);

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::firstOrFail();
        $product2 = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Second Product',
            'slug' => 'second-product',
            'is_active' => true,
        ]);
        $product2->ensureDefaultVariation()->update(['stock_quantity' => 50]);
        ProductPrice::create([
            'product_id' => $product2->id,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 800.00,
        ]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage("You have reached the maximum limit of {$this->campaign->max_products_per_seller} products for this campaign.");

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $product2->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_discount_is_below_campaign_minimum(): void
    {
        $this->campaign->update(['min_discount_percentage' => 15.00]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage("Discount must be at least {$this->campaign->min_discount_percentage}%.");

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 10, // 10% < 15%
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_discount_exceeds_campaign_maximum(): void
    {
        $this->campaign->update(['max_discount_percentage' => 40.00]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage("Discount cannot exceed {$this->campaign->max_discount_percentage}%.");

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 50, // 50% > 40%
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_product_has_no_valid_retail_price(): void
    {
        ProductPrice::where('product_id', $this->product->id)->delete();

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Product must have a valid retail price.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_campaign_price_is_zero_or_negative(): void
    {
        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Campaign price must be greater than zero.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::FixedPrice,
            'discount_value' => 0,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_campaign_price_exceeds_or_equals_base_price(): void
    {
        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Campaign price must be lower than the original retail price.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::FixedPrice,
            'discount_value' => 1000.00,
            'stock_limit' => 10,
        ]);
    }

    public function test_rejects_submission_if_campaign_stock_is_zero_or_negative(): void
    {
        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Campaign stock must be at least 1 unit.');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 0,
        ]);
    }

    public function test_rejects_submission_if_campaign_stock_is_below_minimum(): void
    {
        $this->campaign->update(['min_stock_per_product' => 10]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage("Campaign stock must be at least {$this->campaign->min_stock_per_product} units.");

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 5, // 5 < 10
        ]);
    }

    public function test_rejects_submission_if_campaign_stock_exceeds_physical_variation_stock(): void
    {
        $this->product->variations()->update(['stock_quantity' => 15]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('Campaign stock (20) cannot exceed your current physical warehouse stock (15).');

        $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 20, // 20 > 15 physical stock
        ]);
    }

    public function test_successful_submission_creates_pending_flash_sale_product_when_approval_required(): void
    {
        $this->campaign->update(['requires_approval' => true]);

        $submission = $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 25.0,
            'stock_limit' => 20,
            'per_customer_limit' => 2,
        ]);

        $this->assertInstanceOf(FlashSaleProduct::class, $submission);
        $this->assertSame(FlashSaleSubmissionStatus::Pending, $submission->status);
        $this->assertEquals(1000.00, (float) $submission->original_retail_price);
        $this->assertEquals(750.00, (float) $submission->campaign_price);
        $this->assertSame(20, $submission->stock_limit);
        $this->assertSame(0, $submission->units_sold);
        $this->assertSame(2, $submission->per_customer_limit);

        $this->assertDatabaseHas('flash_sale_products', [
            'id' => $submission->id,
            'flash_sale_id' => $this->campaign->id,
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending->value,
            'campaign_price' => 750.00,
            'stock_limit' => 20,
        ]);
    }

    public function test_successful_submission_creates_approved_flash_sale_product_when_approval_not_required(): void
    {
        $this->campaign->update(['requires_approval' => false]);

        $submission = $this->service->submit($this->seller, $this->campaign, [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::FixedAmount,
            'discount_value' => 200.0,
            'stock_limit' => 15,
        ]);

        $this->assertInstanceOf(FlashSaleProduct::class, $submission);
        $this->assertSame(FlashSaleSubmissionStatus::Approved, $submission->status);
        $this->assertEquals(1000.00, (float) $submission->original_retail_price);
        $this->assertEquals(800.00, (float) $submission->campaign_price);
        $this->assertSame(15, $submission->stock_limit);

        $this->assertDatabaseHas('flash_sale_products', [
            'id' => $submission->id,
            'status' => FlashSaleSubmissionStatus::Approved->value,
            'campaign_price' => 800.00,
        ]);
    }
}
