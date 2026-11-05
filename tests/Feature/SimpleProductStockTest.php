<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-011: products without variations get a default variation, so stock is reserved like any other SKU.
 */
class SimpleProductStockTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
    }

    private function makeSimpleProduct(): Product
    {
        $source = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $product = $source->replicate(['slug', 'sku']);
        $product->slug = 'simple-stock-product';
        $product->sku = 'SIMPLE-1';
        $product->has_variations = false;
        $product->save();

        foreach ($source->prices()->whereNull('variation_id')->get() as $price) {
            $copy = $price->replicate();
            $copy->product_id = $product->id;
            $copy->save();
        }

        return $product->fresh();
    }

    public function test_new_simple_product_gets_default_variation_with_zero_stock(): void
    {
        $product = $this->makeSimpleProduct();

        $this->assertCount(1, $product->variations);
        $this->assertSame('SIMPLE-1-DEFAULT', $product->variations->first()->sku);
        $this->assertSame(0, $product->variations->first()->stock_quantity);
    }

    public function test_simple_product_with_stock_one_cannot_be_ordered_twice(): void
    {
        $product = $this->makeSimpleProduct();
        $product->variations()->update(['stock_quantity' => 1]);
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem($product->id, null, SalesChannel::RETAIL, 1);
        $line = collect($cart->getItems())->firstWhere('product_id', $product->id);
        $this->assertSame($product->variations->first()->id, $line['variation_id'], 'Cart must resolve the default variation');

        $payload = fn () => [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_amount' => 70.0,
            'discount_amount' => 0.0,
            'shipping_address' => ['recipient_name' => 'R', 'recipient_phone' => '01711000000', 'street_address' => 'House 1', 'city' => 'Dhaka', 'postal_code' => '1205'],
            'items' => [[
                'product_id' => $product->id,
                'variation_id' => $line['variation_id'],
                'sales_channel_id' => $line['sales_channel_id'],
                'quantity' => 1,
                'unit_price' => (float) $line['unit_price'],
            ]],
        ];

        $splitter = app(OrderSplitterService::class);
        $splitter->createAndSplitOrder($customer, $payload());
        $this->assertSame(0, $product->variations()->first()->stock_quantity);

        $this->expectException(InsufficientStockException::class);
        $splitter->createAndSplitOrder($customer, $payload());
    }

    public function test_existing_simple_products_get_default_variation(): void
    {
        $product = $this->makeSimpleProduct();
        // Simulate a product created before this fix: no variation rows at all.
        $product->variations()->delete();

        $migration = require database_path('migrations/2026_11_05_000001_create_default_variations_for_simple_products.php');
        $migration->up();

        $variation = ProductVariation::where('product_id', $product->id)->sole();
        $this->assertSame('SIMPLE-1-DEFAULT', $variation->sku);
        $this->assertSame(0, $variation->stock_quantity);

        // down() removes only the rows it created
        $migration->down();
        $this->assertSame(0, ProductVariation::where('product_id', $product->id)->count());
        $this->assertGreaterThan(0, DB::table('product_variations')->count(), 'Real variations must survive down()');
    }

    public function test_seller_sets_simple_product_stock_in_form(): void
    {
        $product = $this->makeSimpleProduct();
        $product->update(['buy_price' => 42.5]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldExists('simple_stock_quantity')
            ->fillForm(['simple_stock_quantity' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, $product->variations()->sole()->stock_quantity);
        // Editing must not wipe the private cost price ($hidden on the model)
        $this->assertEquals($product->buy_price, $product->fresh()->buy_price);
    }
}
