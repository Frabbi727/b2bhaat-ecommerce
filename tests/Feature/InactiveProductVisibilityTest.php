<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactiveProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
    }

    public function test_inactive_product_page_is_not_found(): void
    {
        $this->product->update(['is_active' => false]);

        $this->get('/product/'.$this->product->slug)->assertNotFound();
        $this->getJson('/api/v1/catalog/products/'.$this->product->slug)->assertNotFound();
    }

    public function test_product_of_deactivated_seller_is_not_found(): void
    {
        $this->product->seller->update(['is_active' => false]);

        $this->get('/product/'.$this->product->slug)->assertNotFound();
    }

    public function test_product_of_unapproved_seller_is_not_found(): void
    {
        $this->product->seller->sellerProfile->update(['is_approved' => false]);

        $this->get('/product/'.$this->product->slug)->assertNotFound();
    }

    public function test_inactive_product_cannot_be_added_to_cart(): void
    {
        $this->product->update(['is_active' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This product is no longer available.');

        app(CartSessionService::class)->addItem(
            productId: $this->product->id,
            variationId: $this->product->variations()->firstOrFail()->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1,
        );
    }

    public function test_active_product_still_visible(): void
    {
        $this->get('/product/'.$this->product->slug)->assertOk();
        $this->getJson('/api/v1/catalog/products/'.$this->product->slug)->assertOk();
    }
}
