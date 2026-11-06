<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Exceptions\FlashSaleUnavailableException;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Promotions\FlashSaleAllocationService;
use App\Services\Promotions\FlashSaleLifecycleService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Checkout charges a flash price only while its campaign is live and within its limits,
 * and every order remembers the price it was charged.
 */
class FlashSaleCheckoutIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    private ProductVariation $pink;

    private CartSessionService $cart;

    private int $existingOrderId = 0;

    private int $existingOrderItemCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->pink = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        ProductPrice::where('product_id', $this->nails->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($channel) => $channel->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000]);

        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
        $this->existingOrderId = (int) Order::max('id');
        $this->existingOrderItemCount = OrderItem::count();
    }

    public function test_expired_flash_price_in_cart_is_not_charged_at_checkout(): void
    {
        $this->offer();
        $this->addToCart(1);
        $this->assertEqualsWithDelta(800.0, $this->onlyLine()['unit_price'], 0.001);

        $this->travel(3)->hours();

        $this->checkout('01899001101')
            ->assertSet('errorMessage', "Prices changed for {$this->nails->title} because a flash sale ended. Please check your cart and place the order again.");

        $this->assertCount(0, $this->newOrders());
        $line = $this->onlyLine();
        $this->assertEqualsWithDelta(1000.0, $line['unit_price'], 0.001);
        $this->assertNull($line['flash_sale_product_id']);
        $this->assertNull($line['original_unit_price']);

        $this->checkout('01899001101')->assertSet('errorMessage', '');
        $item = $this->newOrders()->sole()->subOrders->sole()->items->sole();
        $this->assertEqualsWithDelta(1000.0, (float) $item->unit_price, 0.001);
        $this->assertNull($item->flash_sale_product_id);
        $this->assertNull($item->original_unit_price);
    }

    public function test_turn_off_now_between_cart_and_checkout_blocks_the_flash_price(): void
    {
        $offer = $this->offer();
        $this->addToCart(1);

        app(FlashSaleLifecycleService::class)->endNow($offer->flashSale);

        $this->checkout('01899001102')
            ->assertSet('errorMessage', "Prices changed for {$this->nails->title} because a flash sale ended. Please check your cart and place the order again.");

        $this->assertCount(0, $this->newOrders());
        $this->assertEqualsWithDelta(1000.0, $this->onlyLine()['unit_price'], 0.001);
    }

    public function test_cart_page_reprices_a_line_after_its_campaign_is_paused(): void
    {
        $offer = $this->offer();
        $this->addToCart(2);

        app(FlashSaleLifecycleService::class)->pause($offer->flashSale);

        Livewire::test(CartComponent::class)
            ->assertViewHas('subtotal', fn (float $subtotal): bool => abs($subtotal - 2000.0) < 0.001);

        $line = $this->onlyLine();
        $this->assertEqualsWithDelta(1000.0, $line['unit_price'], 0.001);
        $this->assertNull($line['flash_sale_product_id']);
    }

    public function test_campaign_stock_limit_cannot_be_oversold(): void
    {
        $offer = $this->offer(stockLimit: 5);

        $this->addToCart(3);
        $this->checkout('01899001103')->assertSet('errorMessage', '');
        $this->assertSame(3, $offer->unitsSold());
        $itemCountAfterFirstOrder = OrderItem::count();

        $this->cart->clear();
        $this->addToCart(3);
        $this->checkout('01899001104')
            ->assertSet('errorMessage', "Only 2 left at the flash sale price for {$this->nails->title}.");

        $this->assertCount(1, $this->newOrders());
        $this->assertSame($itemCountAfterFirstOrder, OrderItem::count());
        $this->assertSame(3, $offer->unitsSold());
    }

    public function test_per_customer_limit_counts_earlier_orders(): void
    {
        $offer = $this->offer(perCustomerLimit: 2);
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($customer);

        $this->addToCart(2);
        $this->checkout('01899001105')->assertSet('errorMessage', '');

        $this->cart->clear();
        $this->addToCart(1);
        $this->checkout('01899001105')
            ->assertSet('errorMessage', "You can buy up to 2 of {$this->nails->title} at the flash sale price.");

        $this->assertCount(1, $this->newOrders());
        $this->assertSame(2, $offer->unitsBoughtBy($customer->id));
    }

    public function test_guest_with_same_phone_cannot_exceed_per_customer_limit(): void
    {
        $this->offer(perCustomerLimit: 2);

        $this->addToCart(2);
        $this->checkout('01899001106')->assertSet('errorMessage', '');

        $this->cart->clear();
        $this->addToCart(1);
        $this->checkout('+8801899001106')
            ->assertSet('errorMessage', "You can buy up to 2 of {$this->nails->title} at the flash sale price.");

        $this->assertCount(1, $this->newOrders());
    }

    public function test_limit_counts_every_line_of_one_offer_together(): void
    {
        $this->offer(perCustomerLimit: 3);
        $black = ProductVariation::where('sku', 'NAIL-BLK-LNG')->firstOrFail();

        $this->addToCart(2);
        $this->cart->addItem($this->nails->id, $black->id, SalesChannel::RETAIL, 2);

        $this->checkout('01899001107')
            ->assertSet('errorMessage', "You can buy up to 3 of {$this->nails->title} at the flash sale price.");

        $this->assertCount(0, $this->newOrders());
    }

    public function test_cancelled_order_returns_units_to_the_campaign(): void
    {
        $offer = $this->offer(stockLimit: 3);

        $this->addToCart(3);
        $this->checkout('01899001108')->assertSet('errorMessage', '');
        $this->assertSame(3, $offer->unitsSold());

        $subOrder = $this->newOrders()->sole()->subOrders->sole();
        app(OrderDisputeService::class)->cancelSubOrder($subOrder);
        $this->assertSame(0, $offer->unitsSold());

        $this->cart->clear();
        $this->addToCart(3);
        $this->checkout('01899001109')->assertSet('errorMessage', '');

        $this->assertCount(2, $this->newOrders());
        $this->assertSame(3, $offer->unitsSold());
    }

    public function test_order_keeps_its_flash_price_after_the_campaign_ends(): void
    {
        $offer = $this->offer();
        $this->addToCart(2);
        $this->checkout('01899001110')->assertSet('errorMessage', '');

        $item = $this->newOrders()->sole()->subOrders->sole()->items->sole();
        $this->assertEqualsWithDelta(800.0, (float) $item->unit_price, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $item->original_unit_price, 0.001);
        $this->assertSame($offer->id, $item->flash_sale_product_id);

        app(FlashSaleLifecycleService::class)->endNow($offer->flashSale);

        $item->refresh();
        $this->assertEqualsWithDelta(800.0, (float) $item->unit_price, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $item->original_unit_price, 0.001);
        $this->assertSame($offer->id, $item->flash_sale_product_id);
        $this->assertEqualsWithDelta(1600.0, (float) $item->total_price, 0.001);
    }

    public function test_offer_with_sales_cannot_be_deleted(): void
    {
        $offer = $this->offer();
        $this->addToCart(1);
        $this->checkout('01899001111')->assertSet('errorMessage', '');

        $this->expectException(QueryException::class);

        $offer->delete();
    }

    public function test_offer_that_saves_nothing_does_not_use_up_campaign_quota(): void
    {
        $offer = $this->offer(FlashSaleDiscountType::FixedPrice, 1200, stockLimit: 1);

        $this->addToCart(2);
        $line = $this->onlyLine();
        $this->assertEqualsWithDelta(1000.0, $line['unit_price'], 0.001);
        $this->assertNull($line['flash_sale_product_id']);
        $this->assertNull($line['original_unit_price']);

        $this->checkout('01899001112')->assertSet('errorMessage', '');

        $this->assertCount(1, $this->newOrders());
        $this->assertSame(0, $offer->unitsSold());
    }

    public function test_claim_runs_inside_the_order_transaction(): void
    {
        $this->offer();
        $this->addToCart(2);
        $stockBefore = (int) $this->pink->fresh()->stock_quantity;
        $levelOutside = DB::transactionLevel();

        $spy = new class extends FlashSaleAllocationService
        {
            public ?int $transactionLevel = null;

            public function claim(User $customer, array $items): void
            {
                $this->transactionLevel = DB::transactionLevel();

                throw FlashSaleUnavailableException::onlyLeft(0, 'Nails');
            }
        };
        $this->app->instance(FlashSaleAllocationService::class, $spy);

        $this->checkout('01899001113')
            ->assertSet('errorMessage', 'Only 0 left at the flash sale price for Nails.');

        $this->assertGreaterThan($levelOutside, $spy->transactionLevel);
        $this->assertCount(0, $this->newOrders());
        $this->assertSame(0, SubOrder::whereIn('parent_order_id', $this->newOrders()->pluck('id'))->count());
        $this->assertSame($this->existingOrderItemCount, OrderItem::count());
        $this->assertSame($stockBefore, (int) $this->pink->fresh()->stock_quantity);
    }

    public function test_sale_ending_while_the_order_is_placed_refreshes_the_cart(): void
    {
        $this->offer();
        $this->addToCart(1);

        $this->app->instance(FlashSaleAllocationService::class, new class extends FlashSaleAllocationService
        {
            public function claim(User $customer, array $items): void
            {
                $this->travelPastTheSale();
                parent::claim($customer, $items);
            }

            private function travelPastTheSale(): void
            {
                Carbon::setTestNow(now()->addHours(3));
            }
        });

        $this->checkout('01899001114')
            ->assertSet('errorMessage', "The flash sale for {$this->nails->title} has ended.");

        $this->assertCount(0, $this->newOrders());
        $line = $this->onlyLine();
        $this->assertEqualsWithDelta(1000.0, $line['unit_price'], 0.001);
        $this->assertNull($line['flash_sale_product_id']);
    }

    private function offer(
        FlashSaleDiscountType $type = FlashSaleDiscountType::Percentage,
        float $value = 20,
        ?int $stockLimit = null,
        ?int $perCustomerLimit = null,
    ): FlashSaleProduct {
        return FlashSaleProduct::factory()->create([
            'flash_sale_id' => FlashSale::factory()->live()->create()->id,
            'product_id' => $this->nails->id,
            'discount_type' => $type,
            'discount_value' => $value,
            'stock_limit' => $stockLimit,
            'per_customer_limit' => $perCustomerLimit,
        ]);
    }

    private function addToCart(int $quantity): void
    {
        $this->cart->addItem($this->nails->id, $this->pink->id, SalesChannel::RETAIL, $quantity);
    }

    private function checkout(string $phone): Testable
    {
        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Flash Buyer')
            ->set('recipientPhone', $phone)
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder');
    }

    /**
     * @return Collection<int, Order>
     */
    private function newOrders(): Collection
    {
        return Order::where('id', '>', $this->existingOrderId)->with('subOrders.items')->get();
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
}
