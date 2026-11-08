<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductBulkImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * INV-1 / INV-2 / MON-9: every way of making a product visible respects the plan's listing limit and
 * store suspension, not only the single "Visible" switch.
 */
class ListingActivationGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerProfile $profile;

    private Category $category;

    private Unit $unit;

    private SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Quota Test Store',
            'store_slug' => 'quota-test-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $plan = SellerPlan::create([
            'name' => 'Two Listings',
            'slug' => 'two-listings',
            'subscription_price' => 0,
            'max_active_products' => 2,
            'is_listing_limit_enabled' => true,
            'is_active' => true,
        ]);
        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->category = Category::create(['name' => 'Fabrics', 'slug' => 'fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function product(string $title, bool $active): Product
    {
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => $active,
        ]);
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 500]);
        $product->ensureDefaultVariation();

        return $product;
    }

    public function test_bulk_show_is_refused_when_it_would_exceed_the_listing_limit(): void
    {
        $this->product('Live One', true);
        $hiddenA = $this->product('Hidden A', false);
        $hiddenB = $this->product('Hidden B', false);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('show', [$hiddenA, $hiddenB]);

        $this->assertSame(1, Product::where('seller_id', $this->seller->id)->where('is_active', true)->count());
    }

    public function test_bulk_show_within_the_limit_still_works(): void
    {
        $hiddenA = $this->product('Hidden A', false);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('show', [$hiddenA]);

        $this->assertTrue($hiddenA->fresh()->is_active);
    }

    public function test_bulk_show_is_refused_while_the_store_is_suspended(): void
    {
        $hidden = $this->product('Hidden A', false);
        $this->profile->update(['monetization_status' => 'suspended']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('show', [$hidden]);

        $this->assertFalse($hidden->fresh()->is_active);
    }

    public function test_edit_form_cannot_publish_a_draft_beyond_the_limit(): void
    {
        $this->product('Live One', true);
        $this->product('Live Two', true);
        $draft = $this->product('Draft', false);

        Livewire::test(EditProduct::class, ['record' => $draft->getRouteKey()])
            ->fillForm(['is_active' => true])
            ->call('save');

        $this->assertFalse($draft->fresh()->is_active);
    }

    public function test_bulk_import_saves_over_limit_products_hidden_and_says_so(): void
    {
        $this->product('Live One', true);

        $columns = array_keys(ProductBulkImportService::columns());
        $row = fn (string $title): array => array_merge(array_fill_keys($columns, ''), [
            'product_title' => $title,
            'category' => 'Fabrics',
            'sell_to' => 'retail',
            'unit' => 'pcs',
            'stock' => '5',
            'retail_price' => '300',
            'visible' => 'yes',
        ]);
        $lines = [implode(',', $columns)];
        foreach ([$row('Imported A'), $row('Imported B')] as $cells) {
            $lines[] = implode(',', $cells);
        }

        $result = app(ProductBulkImportService::class)->import($this->seller, implode("\n", $lines));

        $this->assertSame(2, $result['products_created']);
        $this->assertSame(['Imported B'], $result['hidden_over_limit']);
        $this->assertTrue(Product::where('title', 'Imported A')->value('is_active'));
        $this->assertFalse(Product::where('title', 'Imported B')->value('is_active'));
    }
}
