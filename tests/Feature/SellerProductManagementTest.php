<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCodeGenerator;
use App\Services\Inventory\ProductDuplicationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seller product management after the dashboard redesign: generated SKUs and barcodes, draft publishing,
 * variation options, specifications round-trip, duplication, safe deletion and seller isolation.
 */
class SellerProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->nails = Product::where('seller_id', $this->seller->id)->where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    /**
     * @return array<string, mixed>
     */
    private function newProductData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Gel Nail Polish Kit',
            'slug' => 'gel-nail-polish-kit',
            'category_id' => Category::where('is_active', true)->value('id'),
            'unit_id' => Unit::value('id'),
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'has_variations' => false,
            'simple_stock_quantity' => 25,
            'is_active' => true,
            'prices' => [[
                'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
                'unit_price' => 350,
                'min_quantity' => 1,
                'max_quantity' => null,
            ]],
        ], $overrides);
    }

    public function test_new_product_gets_generated_sku_and_valid_barcode(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData())
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $product = Product::where('slug', 'gel-nail-polish-kit')->sole();
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{6}$/', $product->sku);
        $this->assertStringEndsWith(str_pad((string) $product->id, 6, '0', STR_PAD_LEFT), $product->sku);
        $this->assertSame(ProductCodeGenerator::barcode($product->id), $product->barcode);
        $this->assertSame(13, strlen($product->barcode));
        $this->assertSame(ProductCodeGenerator::ean13CheckDigit(substr($product->barcode, 0, 12)), (int) substr($product->barcode, -1));
        $this->assertTrue($product->is_active);
        $this->assertSame(25, $product->variations()->sole()->stock_quantity);
        $this->assertSame($product->sku.Product::DEFAULT_VARIATION_SUFFIX, $product->variations()->sole()->sku);
    }

    public function test_seller_cannot_type_their_own_sku(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData(['sku' => 'HANDTYPED-1', 'barcode' => '1234567890123']))
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $product = Product::where('slug', 'gel-nail-polish-kit')->sole();
        $this->assertNotSame('HANDTYPED-1', $product->sku);
        $this->assertSame(ProductCodeGenerator::barcode($product->id), $product->barcode);
    }

    public function test_save_as_draft_creates_a_hidden_product(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData())
            ->callAction('saveDraft')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertFalse(Product::where('slug', 'gel-nail-polish-kit')->sole()->is_active);
    }

    public function test_variations_get_sequential_skus_and_keep_their_options(): void
    {
        $black = AttributeValue::where('value', 'Black')->firstOrFail();
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData([
                'has_variations' => true,
                'variations' => [
                    ['stock_quantity' => 5, 'buy_price' => 100, 'is_active' => true, 'attributeValues' => [$black->id]],
                    ['stock_quantity' => 8, 'buy_price' => 100, 'is_active' => true, 'attributeValues' => []],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $product = Product::where('slug', 'gel-nail-polish-kit')->sole();
        $variations = $product->variations()->orderBy('id')->with('attributeValues')->get();

        $this->assertSame([$product->sku.'-01', $product->sku.'-02'], $variations->pluck('sku')->all());
        $this->assertSame([5, 8], $variations->pluck('stock_quantity')->all());
        $this->assertSame([$black->id], $variations->first()->attributeValues->modelKeys());
    }

    public function test_editing_keeps_specifications_features_and_codes_intact(): void
    {
        $specifications = ['Display' => ['Size' => '55 in', 'Panel' => 'QLED'], 'In the box' => 'Remote and stand'];
        $features = ['Dolby Vision', 'Google TV'];
        $this->nails->forceFill(['specifications' => $specifications, 'features' => $features])->save();
        $sku = $this->nails->refresh()->sku;
        $barcode = $this->nails->barcode;

        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->fillForm(['title' => 'Luxury Press-on Nails (Renamed)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->nails->refresh();
        $this->assertSame('Luxury Press-on Nails (Renamed)', $this->nails->title);
        $this->assertSame($specifications, $this->nails->specifications);
        $this->assertSame($features, $this->nails->features);
        $this->assertSame($sku, $this->nails->sku);
        $this->assertSame($barcode, $this->nails->barcode);
    }

    public function test_duplicate_creates_hidden_copy_with_fresh_codes_and_zero_stock(): void
    {
        $copy = app(ProductDuplicationService::class)->duplicate($this->nails);

        $this->assertFalse($copy->is_active);
        $this->assertNotSame($this->nails->sku, $copy->sku);
        $this->assertSame(ProductCodeGenerator::barcode($copy->id), $copy->barcode);
        $this->assertSame($this->nails->variations()->count(), $copy->variations()->count());
        $this->assertSame(0, (int) $copy->variations()->sum('stock_quantity'));
        $this->assertSame($this->nails->prices()->count(), $copy->prices()->count());
        $this->assertSame($this->nails->images()->count(), $copy->images()->count());
        $this->assertSame($this->seller->id, $copy->seller_id);
    }

    public function test_duplicate_action_redirects_to_the_copy(): void
    {
        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('duplicate')->table($this->nails))
            ->assertHasNoErrors();

        $this->assertSame(1, Product::where('slug', 'luxury-press-on-fake-nails-set-copy')->count());
    }

    public function test_product_with_orders_is_hidden_instead_of_deleted(): void
    {
        $orderId = OrderItem::query()->value('id');
        if (! $orderId) {
            $this->markTestSkipped('Demo seed has no order items.');
        }
        OrderItem::query()->whereKey($orderId)->update(['product_id' => $this->nails->id]);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', [$this->nails])
            ->assertHasNoErrors();

        $this->assertModelExists($this->nails);
        $this->assertFalse($this->nails->refresh()->is_active);
    }

    public function test_bulk_visibility_changes_only_the_sellers_own_products(): void
    {
        Livewire::test(ListProducts::class)
            ->callTableBulkAction('hide', [$this->nails])
            ->assertHasNoErrors();

        $this->assertFalse($this->nails->refresh()->is_active);
    }

    public function test_seller_cannot_see_or_edit_another_sellers_product(): void
    {
        $other = User::factory()->create(['role_id' => Role::where('slug', 'seller')->value('id')]);
        $this->actingAs($other);

        Livewire::test(ListProducts::class)
            ->assertCanNotSeeTableRecords([$this->nails]);

        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->assertNotFound();
    }

    public function test_seeded_product_with_remote_photo_urls_can_be_saved(): void
    {
        $this->nails->images()->delete();
        $this->nails->images()->create(['image_url' => 'https://images.unsplash.com/photo-test?w=800', 'sort_order' => 0]);

        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->fillForm(['short_description' => 'Updated summary'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://images.unsplash.com/photo-test?w=800', $this->nails->images()->sole()->image_url);
        $this->assertTrue($this->nails->images()->sole()->is_primary);
    }

    public function test_products_can_be_found_by_barcode(): void
    {
        $this->nails->refresh();

        Livewire::test(ListProducts::class)
            ->searchTable($this->nails->barcode)
            ->assertCanSeeTableRecords([$this->nails]);
    }

    public function test_seller_panel_pages_render(): void
    {
        $this->get('/seller')->assertSuccessful()->assertSee('Sales')->assertSee('Catalog');
        $this->get('/seller/products')->assertSuccessful()->assertSee('Add product');
        $this->get('/seller/products/create')->assertSuccessful()->assertSee('Send for review')->assertSee('Save as hidden draft');
        $this->get("/seller/products/{$this->nails->id}/edit")->assertSuccessful()->assertSee('Save changes');
        $this->get('/seller/sub-orders')->assertSuccessful();
    }
}
