<?php

namespace Tests\Feature;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ChannelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_guest_and_retail_user_cannot_see_wholesale_prices(): void
    {
        $product = Product::with(['prices.salesChannel', 'variations.attributeValues.attribute', 'images', 'category', 'brand', 'unit'])
            ->where('slug', 'luxury-press-on-fake-nails-set')
            ->firstOrFail();

        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();

        $request = Request::create('/api/v1/catalog/products/'.$product->slug);
        $request->setUserResolver(fn () => $retailUser);

        $resource = new ProductResource($product);
        $data = $resource->toArray($request);

        // Retail user gets retail_price
        $this->assertEquals(250.00, $data['pricing']['retail_price']);
        $this->assertArrayNotHasKey('wholesale_price', $data['pricing']);

        // Retail user receives wholesale teaser
        $this->assertNotNull($data['wholesale_teaser']);
        $this->assertTrue($data['wholesale_teaser']['available']);

        // buy_price must never be present
        $this->assertArrayNotHasKey('buy_price', $data);
    }

    public function test_wholesale_user_sees_wholesale_pricing_and_moq(): void
    {
        $product = Product::with(['prices.salesChannel', 'variations.attributeValues.attribute', 'images', 'category', 'brand', 'unit'])
            ->where('slug', 'luxury-press-on-fake-nails-set')
            ->firstOrFail();

        $wholesaleUser = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $request = Request::create('/api/v1/catalog/products/'.$product->slug);
        $request->setUserResolver(fn () => $wholesaleUser);

        $resource = new ProductResource($product);
        $data = $resource->toArray($request);

        // Wholesale user gets wholesale_price and MOQ
        $this->assertEquals(110.00, $data['pricing']['wholesale_price']);
        $this->assertEquals(50, $data['pricing']['min_order_quantity']);

        // Wholesale teaser is null
        $this->assertNull($data['wholesale_teaser']);

        // buy_price is not leaked
        $this->assertArrayNotHasKey('buy_price', $data);
    }
}
