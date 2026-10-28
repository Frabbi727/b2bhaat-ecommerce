<?php

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockBulkUpdateService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class BulkStockImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_export_csv_stock_sheet(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->actingAs($seller);

        $response = $this->get(route('seller.products.stock.export'));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('b2bhaat-stock-inventory-nailsfactory-ltd', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('variation_id', $content);
        $this->assertStringContainsString('sku', $content);
        $this->assertStringContainsString('product_title', $content);
        $this->assertStringContainsString('current_stock_quantity', $content);
        $this->assertStringContainsString('new_stock_quantity', $content);
    }

    public function test_guest_and_retail_buyer_cannot_export_stock_sheet(): void
    {
        // Guest
        $this->get(route('seller.products.stock.export'))
            ->assertRedirect();

        // Retail buyer
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $this->get(route('seller.products.stock.export'))
            ->assertStatus(403);
    }

    public function test_admin_can_export_any_seller_stock_sheet(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $this->actingAs($admin);

        $response = $this->get(route('seller.products.stock.export', ['seller_id' => $seller->id]));

        $response->assertStatus(200);
        $this->assertStringContainsString('b2bhaat-stock-inventory-nailsfactory-ltd', $response->headers->get('Content-Disposition'));
    }

    public function test_bulk_stock_service_updates_inventory_via_csv(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = $seller->products()->firstOrFail();
        $variations = $product->variations()->get();

        $v1 = $variations[0];
        $v2 = $variations[1];

        $csvText = "variation_id,sku,new_stock_quantity\n{$v1->id},{$v1->sku},450\n{$v2->id},{$v2->sku},600\n";

        $service = app(StockBulkUpdateService::class);
        $result = $service->importCsvForSeller($seller, $csvText);

        $this->assertSame(2, $result['updated']);
        $this->assertEmpty($result['errors']);

        $this->assertSame(450, $v1->fresh()->stock_quantity);
        $this->assertSame(600, $v2->fresh()->stock_quantity);
    }

    public function test_bulk_stock_service_enforces_multi_tenant_isolation(): void
    {
        $seller1 = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller2 = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Other Merchant',
            'email' => 'other@merchant.com',
            'password' => bcrypt('password'),
        ]);

        $category = Category::firstOrFail();
        $salesChannel = SalesChannel::firstOrFail();
        $unit = Unit::firstOrFail();

        $product2 = Product::create([
            'seller_id' => $seller2->id,
            'category_id' => $category->id,
            'sales_channel_id' => $salesChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Other Seller Item',
            'slug' => 'other-seller-item',
            'is_active' => true,
        ]);

        $variation2 = $product2->variations()->create([
            'sku' => 'OTHER-SKU-999',
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        $csvText = "variation_id,sku,new_stock_quantity\n{$variation2->id},{$variation2->sku},9999\n";

        $service = app(StockBulkUpdateService::class);
        $result = $service->importCsvForSeller($seller1, $csvText);

        $this->assertSame(0, $result['updated']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not found in your catalog', $result['errors'][0]);

        // Stock must remain unchanged
        $this->assertSame(50, $variation2->fresh()->stock_quantity);
    }

    public function test_web_controller_imports_stock_via_uploaded_csv_file(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $variation = $seller->products()->firstOrFail()->variations()->firstOrFail();

        $this->actingAs($seller);

        $csvContent = "variation_id,sku,new_stock_quantity\n{$variation->id},{$variation->sku},320\n";
        $uploadedFile = UploadedFile::fake()->createWithContent('stock_update.csv', $csvContent);

        $response = $this->post(route('seller.products.stock.import'), [
            'csv_file' => $uploadedFile,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(320, $variation->fresh()->stock_quantity);
    }

    public function test_filament_list_products_action_updates_stock_from_modal(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $variation = $seller->products()->firstOrFail()->variations()->firstOrFail();

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $csvContent = "variation_id,sku,new_stock_quantity\n{$variation->id},{$variation->sku},777\n";

        Livewire::test(ListProducts::class)
            ->callAction('bulk_update_stock', [
                'csv_text' => $csvContent,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(777, $variation->fresh()->stock_quantity);
    }
}
