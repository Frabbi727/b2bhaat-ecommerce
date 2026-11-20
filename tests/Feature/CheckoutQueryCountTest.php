<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\ShippingCalculatorService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 4.4 (AR-18): checkout renders with settings and shipping quotes looked up once per request, not once per
 * delivery zone and per call.
 */
class CheckoutQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'customer@retail.com')->firstOrFail());
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);
    }

    public function test_the_checkout_page_queries_stay_within_budget(): void
    {
        Livewire::test(CheckoutComponent::class); // warm the shared caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CheckoutComponent::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 18 before 4.4, 9 after
        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_quotes_for_every_zone_load_the_products_once(): void
    {
        $items = app(CartSessionService::class)->getItems();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $quotes = app(ShippingCalculatorService::class)->calculateAllZones($items);
        $productQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "products"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $productQueries);
        $this->assertSame(ShippingCalculatorService::ZONES, array_keys($quotes));
        foreach (ShippingCalculatorService::ZONES as $zone) {
            $this->assertSame(app(ShippingCalculatorService::class)->calculateShipping($items, $zone), $quotes[$zone]);
        }
    }

    public function test_a_changed_setting_is_read_fresh_after_it_is_saved(): void
    {
        StoreSetting::setTypedValue('free_shipping_threshold', 1000, 'shipping', 'float');
        $this->assertSame(1000.0, StoreSetting::getTypedValue('free_shipping_threshold'));

        StoreSetting::setTypedValue('free_shipping_threshold', 2500, 'shipping', 'float');
        $this->assertSame(2500.0, StoreSetting::getTypedValue('free_shipping_threshold'));
    }
}
