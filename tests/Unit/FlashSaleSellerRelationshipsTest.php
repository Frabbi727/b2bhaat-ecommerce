<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FlashSaleSellerRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flash_sale_has_categories_and_seller_items(): void
    {
        $this->seed(SystemLookupSeeder::class);

        $sale = FlashSale::factory()->create([
            'registration_starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'registration_ends_at' => Carbon::parse('2026-11-05 23:59:59'),
            'starts_at' => Carbon::parse('2026-11-06 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-07 00:00:00'),
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);
        $sale->categories()->attach($category);

        $this->assertTrue($sale->categories->contains($category));

        $this->assertTrue($sale->isRegistrationOpen(Carbon::parse('2026-11-03 12:00:00')));
        $this->assertFalse($sale->isRegistrationOpen(Carbon::parse('2026-10-31 23:59:59')));
        $this->assertFalse($sale->isRegistrationOpen(Carbon::parse('2026-11-06 00:00:00')));

        // Registration is closed if campaign is cancelled or ended
        $sale->status = FlashSaleStatus::Cancelled;
        $this->assertFalse($sale->isRegistrationOpen(Carbon::parse('2026-11-03 12:00:00')));
        $sale->status = FlashSaleStatus::Draft;

        $seller = User::factory()->create();
        $admin = User::factory()->create();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::firstOrFail();

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Sample Product',
            'slug' => 'sample-product',
            'is_active' => true,
        ]);

        $item = FlashSaleProduct::create([
            'flash_sale_id' => $sale->id,
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'original_retail_price' => 1000.00,
            'campaign_price' => 800.00,
            'stock_limit' => 50,
            'units_sold' => 0,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->assertSame($seller->id, $item->seller->id);
        $this->assertSame(FlashSaleSubmissionStatus::Pending, $item->status);
        $this->assertSame($admin->id, $item->reviewer->id);
    }
}
