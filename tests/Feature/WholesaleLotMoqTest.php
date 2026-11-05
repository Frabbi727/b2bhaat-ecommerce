<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-025 / D7: wholesale MOQ and tier pricing apply to the product lot (all variations together).
 * Demo tiers: 50–99 @ ৳110, 100–249 @ ৳95, 250+ @ ৳85.
 */
class WholesaleLotMoqTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private int $v1;

    private int $v2;

    private CartSessionService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail());
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        [$first, $second] = $this->product->variations()->orderBy('id')->take(2)->get()->all();
        $this->v1 = $first->id;
        $this->v2 = $second->id;

        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
    }

    private function key(int $variationId): string
    {
        return "{$this->product->id}_{$variationId}_".SalesChannel::WHOLESALE;
    }

    private function addLot(int $qtyA, int $qtyB): void
    {
        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->set("matrixQuantities.{$this->v1}", $qtyA)
            ->set("matrixQuantities.{$this->v2}", $qtyB)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');
    }

    public function test_matrix_lot_across_variations_meets_moq_and_is_not_quoted(): void
    {
        $this->addLot(30, 20);

        $items = $this->cart->getItems();
        $this->assertSame(30, $items[$this->key($this->v1)]['quantity']);
        $this->assertSame(20, $items[$this->key($this->v2)]['quantity']);
        $this->assertFalse($items[$this->key($this->v1)]['is_quoted']);
        $this->assertEquals(110.0, $items[$this->key($this->v1)]['unit_price']);
        $this->assertSame(50, $items[$this->key($this->v1)]['lot_quantity']);
    }

    public function test_increasing_one_line_keeps_lot_tier_price(): void
    {
        $this->addLot(30, 20);

        // Line alone (31) is below MOQ; the lot (51) is not — allowed, priced on the lot.
        $this->cart->updateQuantity($this->key($this->v1), 31);

        $items = $this->cart->getItems();
        $this->assertSame(31, $items[$this->key($this->v1)]['quantity']);
        $this->assertEquals(110.0, $items[$this->key($this->v1)]['unit_price']);
        $this->assertEquals(110.0, $items[$this->key($this->v2)]['unit_price']);
    }

    public function test_lot_tier_price_drops_when_total_crosses_tier(): void
    {
        $this->addLot(30, 20);

        $this->cart->updateQuantity($this->key($this->v1), 80); // lot 100 → ৳95

        $items = $this->cart->getItems();
        $this->assertEquals(95.0, $items[$this->key($this->v1)]['unit_price']);
        $this->assertEquals(95.0, $items[$this->key($this->v2)]['unit_price'], 'Other lines of the lot must be re-priced too');
        $this->assertEqualsWithDelta(100 * 95.0, $this->cart->getSubtotal(), 0.001);
    }

    public function test_reducing_lot_below_moq_is_refused(): void
    {
        $this->addLot(30, 20);

        $this->expectExceptionMessage('minimum order quantity');
        $this->cart->updateQuantity($this->key($this->v1), 29);
    }

    public function test_removing_a_line_below_lot_moq_blocks_checkout(): void
    {
        $this->addLot(30, 20);
        $this->cart->removeItem($this->key($this->v2));

        $violations = $this->cart->wholesaleMoqViolations();
        $this->assertCount(1, $violations);
        $this->assertSame($this->product->id, $violations[0]['product_id']);
        $this->assertSame(30, $violations[0]['quantity']);
        $this->assertSame(50, $violations[0]['moq']);

        $ordersBefore = Order::count();
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Boutique Buyer')
            ->set('recipientPhone', '01711000000')
            ->set('streetAddress', 'House 1, Road 2')
            ->set('city', 'Dhaka')
            ->call('placeOrder')
            ->assertSet('errorMessage', fn (string $message) => str_contains($message, 'minimum order quantity'));
        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_single_add_below_lot_moq_is_refused(): void
    {
        $this->expectExceptionMessage('minimum order quantity');
        $this->cart->addItem($this->product->id, $this->v1, SalesChannel::WHOLESALE, 10);
    }

    public function test_quoted_rfq_line_keeps_negotiated_price_after_quantity_change(): void
    {
        $this->cart->addItem($this->product->id, null, SalesChannel::WHOLESALE, 40, 70.0);

        $key = "{$this->product->id}_base_".SalesChannel::WHOLESALE;
        $this->assertTrue($this->cart->getItems()[$key]['is_quoted']);

        $this->cart->updateQuantity($key, 120);

        $this->assertSame(120, $this->cart->getItems()[$key]['quantity']);
        $this->assertEquals(70.0, $this->cart->getItems()[$key]['unit_price']);
        $this->assertSame([], $this->cart->wholesaleMoqViolations(), 'Quoted RFQ lines are not subject to catalogue MOQ');
    }
}
