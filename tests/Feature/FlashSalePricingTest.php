<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Livewire\Storefront\ProductCatalog;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Services\Cart\CartSessionService;
use App\Services\Promotions\FlashSalePricing;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign prices reach the storefront and the cart through one path (FlashSalePricing), for retail lines only.
 */
class FlashSalePricingTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    private CartSessionService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
    }

    public function test_retail_cart_line_gets_the_campaign_price_and_remembers_the_offer(): void
    {
        $this->retailBasePrice()->update(['unit_price' => 1000]);
        $offer = $this->offer(FlashSale::factory()->live(), FlashSaleDiscountType::Percentage, 20);

        $this->cart->addItem($this->nails->id, $this->variation('NAIL-PNK-MED')->id, SalesChannel::RETAIL, 1);
        $line = $this->onlyLine();

        $this->assertEqualsWithDelta(800.0, $line['unit_price'], 0.001);
        $this->assertSame($offer->id, $line['flash_sale_product_id']);
        $this->assertEqualsWithDelta(1000.0, $line['original_unit_price'], 0.001);

        $this->cart->updateQuantity($line['key'], 3);
        $line = $this->onlyLine();

        $this->assertEqualsWithDelta(800.0, $line['unit_price'], 0.001);
        $this->assertSame($offer->id, $line['flash_sale_product_id']);
        $this->assertEqualsWithDelta(1000.0, $line['original_unit_price'], 0.001);
    }

    public function test_percentage_discount_is_applied_to_each_variations_own_price(): void
    {
        $this->retailBasePrice()->delete();
        $pink = $this->variation('NAIL-PNK-MED');
        $black = $this->variation('NAIL-BLK-LNG');
        $this->variationRetailPrice($pink, 1000);
        $this->variationRetailPrice($black, 1400);
        $this->offer(FlashSale::factory()->live(), FlashSaleDiscountType::Percentage, 10);

        $this->cart->addItem($this->nails->id, $pink->id, SalesChannel::RETAIL, 1);
        $this->cart->addItem($this->nails->id, $black->id, SalesChannel::RETAIL, 1);
        $items = $this->cart->getItems();

        $this->assertEqualsWithDelta(900.0, $items["{$this->nails->id}_{$pink->id}_retail"]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $items["{$this->nails->id}_{$pink->id}_retail"]['original_unit_price'], 0.001);
        $this->assertEqualsWithDelta(1260.0, $items["{$this->nails->id}_{$black->id}_retail"]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(1400.0, $items["{$this->nails->id}_{$black->id}_retail"]['original_unit_price'], 0.001);
    }

    public function test_wholesale_and_quoted_lines_ignore_campaigns(): void
    {
        $this->offer(FlashSale::factory()->live(), FlashSaleDiscountType::Percentage, 20);
        $pink = $this->variation('NAIL-PNK-MED');
        $black = $this->variation('NAIL-BLK-LNG');

        $this->cart->addItem($this->nails->id, $pink->id, SalesChannel::WHOLESALE, 50);
        $wholesaleKey = "{$this->nails->id}_{$pink->id}_wholesale";
        $this->assertWholesaleOrQuotedLine($this->cart->getItems()[$wholesaleKey], 110.0);

        $this->cart->updateQuantity($wholesaleKey, 120);
        $this->assertWholesaleOrQuotedLine($this->cart->getItems()[$wholesaleKey], 95.0);

        $this->cart->addItem($this->nails->id, $black->id, SalesChannel::RETAIL, 5, overrideUnitPrice: 180.0);
        $this->assertWholesaleOrQuotedLine($this->cart->getItems()["{$this->nails->id}_{$black->id}_retail"], 180.0);
    }

    public function test_upcoming_or_paused_campaign_gives_the_normal_price(): void
    {
        $this->offer(FlashSale::factory()->upcoming(), FlashSaleDiscountType::Percentage, 20);
        $this->offer(FlashSale::factory()->paused(), FlashSaleDiscountType::Percentage, 30);

        $this->cart->addItem($this->nails->id, $this->variation('NAIL-PNK-MED')->id, SalesChannel::RETAIL, 1);
        $line = $this->onlyLine();

        $this->assertEqualsWithDelta(250.0, $line['unit_price'], 0.001);
        $this->assertNull($line['flash_sale_product_id']);
        $this->assertNull($line['original_unit_price']);

        $product = $this->nails->fresh();
        $this->assertNull($product->liveFlashOffer());
        $this->assertFalse($product->is_flash_sale_active);
        $this->assertEqualsWithDelta(250.0, $product->effective_selling_price, 0.001);
        $this->assertFalse(Product::query()->flashSale()->exists());
    }

    public function test_homepage_flash_section_lists_only_live_campaign_products(): void
    {
        Livewire::test(ProductCatalog::class)
            ->assertViewHas('flashSaleProducts', fn ($products): bool => $products->isEmpty())
            ->assertViewHas('flashSaleEndsAt', null);

        $offer = $this->offer(FlashSale::factory()->live(), FlashSaleDiscountType::FixedAmount, 50);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('Flash Sale')
            ->assertSee('data-ends-at="'.$offer->flashSale->ends_at->getTimestampMs().'"', false)
            ->assertSee('৳200');

        Livewire::test(ProductCatalog::class)
            ->assertViewHas('flashSaleProducts', fn ($products): bool => $products->pluck('id')->all() === [$this->nails->id]);
    }

    public function test_discount_sort_does_not_use_removed_columns(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('catalog.index', ['sort' => 'discount']))->assertOk();

        $flashColumnQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'flash_sale_price') || str_contains($sql, 'is_flash_sale'));
        $this->assertSame([], array_values($flashColumnQueries));
    }

    public function test_listing_prices_load_live_offers_in_a_fixed_number_of_queries(): void
    {
        $products = collect([$this->nails]);
        foreach ([400, 600, 800] as $price) {
            $copy = $this->retailProduct("Copy {$price}", $price);
            $this->offer(FlashSale::factory()->live(), FlashSaleDiscountType::Percentage, 25, $copy);
            $products->push($copy);
        }
        $listing = Product::with('retailPrice')->whereIn('id', $products->pluck('id'))->orderBy('id')->get();
        $pricing = app(FlashSalePricing::class);

        DB::enableQueryLog();
        $pricing->primeFor($listing);
        $prices = $listing->map(fn (Product $product): float => $product->effective_selling_price)->all();
        $flags = $listing->map(fn (Product $product): bool => $product->is_flash_sale_active)->all();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queryCount);
        $this->assertEqualsWithDelta([250.0, 300.0, 450.0, 600.0], $prices, 0.001);
        $this->assertSame([false, true, true, true], $flags);
    }

    private function retailBasePrice(): ProductPrice
    {
        return ProductPrice::where('product_id', $this->nails->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($channel) => $channel->where('slug', SalesChannel::RETAIL))
            ->firstOrFail();
    }

    private function variation(string $sku): ProductVariation
    {
        return ProductVariation::where('sku', $sku)->firstOrFail();
    }

    private function variationRetailPrice(ProductVariation $variation, float $price): void
    {
        ProductPrice::create([
            'product_id' => $this->nails->id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'min_quantity' => 1,
            'unit_price' => $price,
        ]);
    }

    private function offer(mixed $saleFactory, FlashSaleDiscountType $type, float $value, ?Product $product = null): FlashSaleProduct
    {
        return FlashSaleProduct::factory()->create([
            'flash_sale_id' => $saleFactory->create()->id,
            'product_id' => ($product ?? $this->nails)->id,
            'discount_type' => $type,
            'discount_value' => $value,
        ]);
    }

    private function retailProduct(string $title, float $price): Product
    {
        $product = Product::create([
            'seller_id' => $this->nails->seller_id,
            'category_id' => $this->nails->category_id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'unit_id' => $this->nails->unit_id,
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $product->sales_channel_id,
            'min_quantity' => 1,
            'unit_price' => $price,
        ]);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyLine(): array
    {
        $items = $this->cart->getItems();
        $this->assertCount(1, $items);

        return reset($items);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function assertWholesaleOrQuotedLine(array $line, float $expectedUnitPrice): void
    {
        $this->assertEqualsWithDelta($expectedUnitPrice, $line['unit_price'], 0.001);
        $this->assertArrayHasKey('flash_sale_product_id', $line);
        $this->assertArrayHasKey('original_unit_price', $line);
        $this->assertNull($line['flash_sale_product_id']);
        $this->assertNull($line['original_unit_price']);
    }
}
