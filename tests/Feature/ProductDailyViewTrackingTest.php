<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDailyViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);

        $seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Views Store',
            'store_slug' => 'views-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Fabrics', 'slug' => 'fabrics', 'is_active' => true])->id,
            'sales_channel_id' => $retail->id,
            'unit_id' => Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs'])->id,
            'title' => 'Cotton Lungi',
            'slug' => 'cotton-lungi',
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => true,
        ]);
        $this->product->prices()->create(['sales_channel_id' => $retail->id, 'min_quantity' => 1, 'unit_price' => 500]);
        $this->product->ensureDefaultVariation();
        $this->product->forceFill(['approval_status' => 'approved', 'approved_at' => now()])->save();
    }

    private function viewProduct(): void
    {
        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])->assertOk();
    }

    public function test_viewing_a_product_records_todays_view(): void
    {
        $this->viewProduct();

        $this->assertDatabaseHas('product_daily_views', [
            'product_id' => $this->product->id,
            'date' => now('Asia/Dhaka')->toDateString(),
            'views' => 1,
        ]);
        $this->assertSame(1, $this->product->fresh()->views_count);
    }

    public function test_second_view_same_day_increments_the_same_row(): void
    {
        $this->viewProduct();
        $this->viewProduct();

        $this->assertDatabaseCount('product_daily_views', 1);
        $this->assertDatabaseHas('product_daily_views', ['product_id' => $this->product->id, 'views' => 2]);
    }

    public function test_a_view_after_dhaka_midnight_starts_a_new_row(): void
    {
        $this->travelTo('2026-11-12 17:59:00');
        $this->viewProduct();
        $this->travelTo('2026-11-12 18:01:00');
        $this->viewProduct();

        $this->assertDatabaseHas('product_daily_views', ['product_id' => $this->product->id, 'date' => '2026-11-12', 'views' => 1]);
        $this->assertDatabaseHas('product_daily_views', ['product_id' => $this->product->id, 'date' => '2026-11-13', 'views' => 1]);
    }
}
