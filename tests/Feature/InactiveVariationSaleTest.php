<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INV-5: an option the seller switched off ("Sellable" off) can't be added to a cart or ordered, in any channel.
 */
class InactiveVariationSaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariation $active;

    private ProductVariation $switchedOff;

    private CartSessionService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->product->variations()->update(['stock_quantity' => 500, 'is_active' => true]);
        $variations = $this->product->variations()->orderBy('id')->get();
        $this->active = $variations[0];
        $this->switchedOff = $variations[1];
        $this->switchedOff->update(['is_active' => false]);

        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
    }

    private function assertRefused(callable $attempt, string $expected = 'no longer sold'): void
    {
        try {
            $attempt();
            $this->fail('The switched-off option must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function test_retail_cart_refuses_a_switched_off_option(): void
    {
        $this->assertRefused(fn () => $this->cart->addItem($this->product->id, $this->switchedOff->id, SalesChannel::RETAIL, 1));

        $this->assertSame([], $this->cart->getItems());
    }

    public function test_wholesale_lot_with_a_switched_off_option_adds_nothing(): void
    {
        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail());

        $this->assertRefused(fn () => $this->cart->addWholesaleLot($this->product->id, [
            $this->active->id => 30,
            $this->switchedOff->id => 20,
        ]));

        $this->assertSame([], $this->cart->getItems());
    }

    public function test_an_option_of_another_product_is_refused(): void
    {
        $other = $this->product->replicate(['slug', 'sku']);
        $other->slug = 'other-product';
        $other->sku = 'OTHER-1';
        $other->save();
        $foreign = $other->variations()->create(['sku' => 'OTHER-01', 'stock_quantity' => 9, 'buy_price' => 0, 'is_active' => true]);

        $this->assertRefused(fn () => $this->cart->addItem($this->product->id, $foreign->id, SalesChannel::RETAIL, 1), 'not part of this product');
    }

    public function test_an_active_option_still_adds(): void
    {
        $this->cart->addItem($this->product->id, $this->active->id, SalesChannel::RETAIL, 1);

        $this->assertCount(1, $this->cart->getItems());
    }

    public function test_checkout_refuses_an_option_switched_off_after_it_was_added(): void
    {
        $this->switchedOff->update(['is_active' => true]);
        $this->cart->addItem($this->product->id, $this->switchedOff->id, SalesChannel::RETAIL, 2);
        $line = collect($this->cart->getItems())->first();

        // The seller switches it off while it sits in the cart.
        $this->switchedOff->update(['is_active' => false]);

        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $ordersBefore = Order::count();

        $this->assertRefused(fn () => app(OrderSplitterService::class)->createAndSplitOrder($customer, [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_amount' => 70.0,
            'discount_amount' => 0.0,
            'shipping_address' => ['recipient_name' => 'R', 'recipient_phone' => '01711000000', 'street_address' => 'House 1', 'city' => 'Dhaka', 'postal_code' => '1205'],
            'items' => [[
                'product_id' => $this->product->id,
                'variation_id' => $this->switchedOff->id,
                'sales_channel_id' => $line['sales_channel_id'],
                'quantity' => 2,
                'unit_price' => (float) $line['unit_price'],
            ]],
        ]), 'Remove it from your cart');

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame(500, $this->switchedOff->fresh()->stock_quantity);
    }

    public function test_the_refusal_is_a_domain_error(): void
    {
        $this->expectException(DomainException::class);

        $this->cart->addItem($this->product->id, $this->switchedOff->id, SalesChannel::RETAIL, 1);
    }
}
