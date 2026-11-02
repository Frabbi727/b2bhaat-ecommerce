<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\HeaderSearch;
use App\Livewire\Storefront\ProductCatalog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SearchDiscoveryAndFacetFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_search_finds_products_by_brand_category_and_seller_store(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $sellerProfile = SellerProfile::where('user_id', $seller->id)->firstOrFail();
        $sellerProfile->update(['store_name' => 'Apex Industrial Zone']);

        $brand = Brand::firstOrCreate(['name' => 'QuantumDisplay Corp'], [
            'slug' => 'quantum-display-corp',
            'is_active' => true,
        ]);

        $category = Category::firstOrCreate(['name' => 'Industrial Optical Sensors'], [
            'slug' => 'industrial-optical-sensors',
            'is_active' => true,
        ]);

        $unit = Unit::firstOrFail();
        $channel = SalesChannel::firstOrFail();

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Advanced Photonics Transceiver 9000',
            'slug' => 'advanced-photonics-transceiver-9000',
            'sku' => 'OPT-9000-X',
            'model_number' => 'MDL-9988-OPT',
            'short_description' => 'Ultra high speed photonic optical node',
            'regular_price' => 12500.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'OPT-9000-X-VAR1',
            'stock_quantity' => 15,
            'is_active' => true,
        ]);

        // 1. Search by Brand name
        Livewire::test(ProductCatalog::class)
            ->set('search', 'QuantumDisplay')
            ->assertSee('Advanced Photonics Transceiver 9000');

        // 2. Search by Seller Store Name
        Livewire::test(ProductCatalog::class)
            ->set('search', 'Apex Industrial')
            ->assertSee('Advanced Photonics Transceiver 9000');

        // 3. Search by Model Number
        Livewire::test(ProductCatalog::class)
            ->set('search', 'MDL-9988')
            ->assertSee('Advanced Photonics Transceiver 9000');

        // 4. HeaderSearch finds by Brand and Category
        Livewire::test(HeaderSearch::class)
            ->set('query', 'QuantumDisplay')
            ->assertSee('Advanced Photonics Transceiver 9000');

        Livewire::test(HeaderSearch::class)
            ->set('query', 'Optical Sensors')
            ->assertSee('Industrial Optical Sensors');
    }

    public function test_in_stock_only_filter_excludes_out_of_stock_products(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $category = Category::firstOrFail();
        $brand = Brand::firstOrFail();
        $unit = Unit::firstOrFail();
        $channel = SalesChannel::firstOrFail();

        // Product A: In stock
        $inStockProduct = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'In-Stock Test Product Alpha',
            'slug' => 'in-stock-test-product-alpha',
            'sku' => 'STOCK-ALPHA',
            'regular_price' => 3000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        ProductVariation::create([
            'product_id' => $inStockProduct->id,
            'sku' => 'STOCK-ALPHA-V1',
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        // Product B: Zero stock
        $outOfStockProduct = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Zero-Stock Test Product Beta',
            'slug' => 'zero-stock-test-product-beta',
            'sku' => 'STOCK-BETA',
            'regular_price' => 4000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        ProductVariation::create([
            'product_id' => $outOfStockProduct->id,
            'sku' => 'STOCK-BETA-V1',
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        // When inStockOnly is false, both can be seen
        Livewire::test(ProductCatalog::class)
            ->set('search', 'Test Product')
            ->assertSee('In-Stock Test Product Alpha')
            ->assertSee('Zero-Stock Test Product Beta');

        // When inStockOnly is true, only Product Alpha is seen
        Livewire::test(ProductCatalog::class)
            ->set('search', 'Test Product')
            ->set('inStockOnly', true)
            ->assertSee('In-Stock Test Product Alpha')
            ->assertDontSee('Zero-Stock Test Product Beta');
    }

    public function test_verified_supplier_filter_excludes_unverified_merchants(): void
    {
        $category = Category::firstOrFail();
        $brand = Brand::firstOrFail();
        $unit = Unit::firstOrFail();
        $channel = SalesChannel::firstOrFail();

        // Approved seller
        $approvedSeller = User::create([
            'name' => 'Approved Seller LLC',
            'email' => 'approved_seller@example.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create([
            'user_id' => $approvedSeller->id,
            'store_name' => 'Approved Electronics Ltd',
            'store_slug' => 'approved-electronics-ltd',
            'is_approved' => true,
        ]);

        $approvedProduct = Product::create([
            'seller_id' => $approvedSeller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Approved Supplier Gadget',
            'slug' => 'approved-supplier-gadget',
            'sku' => 'APPR-GADGET',
            'regular_price' => 5000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        // Unapproved seller
        $unapprovedSeller = User::create([
            'name' => 'Unapproved Seller Inc',
            'email' => 'unapproved_seller@example.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create([
            'user_id' => $unapprovedSeller->id,
            'store_name' => 'Unapproved Merchant Store',
            'store_slug' => 'unapproved-merchant-store',
            'is_approved' => false,
        ]);

        $unapprovedProduct = Product::create([
            'seller_id' => $unapprovedSeller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Unapproved Supplier Trinket',
            'slug' => 'unapproved-supplier-trinket',
            'sku' => 'UNAPPR-TRINKET',
            'regular_price' => 5000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        Livewire::test(ProductCatalog::class)
            ->set('search', 'Supplier')
            ->set('verifiedSupplierOnly', true)
            ->assertSee('Approved Supplier Gadget')
            ->assertDontSee('Unapproved Supplier Trinket');
    }

    public function test_parent_category_filter_includes_child_subcategory_products(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $brand = Brand::firstOrFail();
        $unit = Unit::firstOrFail();
        $channel = SalesChannel::firstOrFail();

        // Parent Category
        $parentCat = Category::create([
            'name' => 'Heavy Machinery Systems',
            'slug' => 'heavy-machinery-systems',
            'parent_id' => null,
            'is_active' => true,
        ]);

        // Child Category
        $childCat = Category::create([
            'name' => 'Hydraulic Excavator Spares',
            'slug' => 'hydraulic-excavator-spares',
            'parent_id' => $parentCat->id,
            'is_active' => true,
        ]);

        // Product assigned directly to child subcategory
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $childCat->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Hydraulic Cylinder 500bar',
            'slug' => 'hydraulic-cylinder-500bar',
            'sku' => 'HYD-500BAR',
            'regular_price' => 24000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        // Filtering by parent category ID MUST retrieve the child category's product
        Livewire::test(ProductCatalog::class)
            ->set('selectedCategoryId', $parentCat->id)
            ->assertSee('Hydraulic Cylinder 500bar');

        // In HeaderSearch, scoping to parent category also retrieves the child's product
        Livewire::test(HeaderSearch::class)
            ->set('query', 'Hydraulic Cylinder')
            ->set('selectedCategory', $parentCat->id)
            ->assertSee('Hydraulic Cylinder 500bar');
    }

    public function test_price_range_filtering_restricts_results_correctly(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $category = Category::firstOrFail();
        $brand = Brand::firstOrFail();
        $unit = Unit::firstOrFail();
        $channel = SalesChannel::firstOrFail();

        $budgetProduct = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Budget Earphones 450',
            'slug' => 'budget-earphones-450',
            'sku' => 'BUDGET-450',
            'regular_price' => 450.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        $midProduct = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Midrange Soundbar 3500',
            'slug' => 'midrange-soundbar-3500',
            'sku' => 'MID-3500',
            'regular_price' => 3500.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        $premiumProduct = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sales_channel_id' => $channel->id,
            'title' => 'Flagship Hi-Fi Rig 85000',
            'slug' => 'flagship-hi-fi-rig-85000',
            'sku' => 'HIFI-85000',
            'regular_price' => 85000.00,
            'is_active' => true,
            'status' => 'published',
        ]);

        // Filter min = 1000, max = 5000
        Livewire::test(ProductCatalog::class)
            ->set('minPrice', 1000.00)
            ->set('maxPrice', 5000.00)
            ->assertSee('Midrange Soundbar 3500')
            ->assertDontSee('Budget Earphones 450')
            ->assertDontSee('Flagship Hi-Fi Rig 85000');
    }

    public function test_reset_filters_restores_catalog_state(): void
    {
        Livewire::test(ProductCatalog::class)
            ->set('search', 'Sony')
            ->set('selectedCategoryId', 10)
            ->set('selectedBrandId', 5)
            ->set('channelFilter', 'wholesale')
            ->set('sort', 'price_desc')
            ->set('minPrice', 500.0)
            ->set('maxPrice', 2000.0)
            ->set('onlyFlashSale', true)
            ->set('inStockOnly', true)
            ->set('verifiedSupplierOnly', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('selectedCategoryId', null)
            ->assertSet('selectedBrandId', null)
            ->assertSet('channelFilter', 'all')
            ->assertSet('sort', 'newest')
            ->assertSet('minPrice', null)
            ->assertSet('maxPrice', null)
            ->assertSet('onlyFlashSale', false)
            ->assertSet('inStockOnly', false)
            ->assertSet('verifiedSupplierOnly', false);
    }
}
