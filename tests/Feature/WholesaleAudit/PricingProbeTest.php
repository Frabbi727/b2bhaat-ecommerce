<?php

declare(strict_types=1);

namespace Tests\Feature\WholesaleAudit;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wholesale audit 2026-11-14, pricing engine probes.
 * Probes named test_wa_* pin today's behaviour of a confirmed defect; the fix inverts the assertion.
 * Probes named test_verified_* pin behaviour the audit found correct.
 * Demo wholesale tiers: 50–99 @ ৳110, 100–249 @ ৳95, 250+ @ ৳85 (MOQ 50, all variations together).
 */
#[Group('wholesale-audit')]
class PricingProbeTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $buyer;

    private CartSessionService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->actingAs($this->buyer);
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
    }

    private function makeSimpleProduct(): Product
    {
        $product = $this->product->replicate(['slug', 'sku']);
        $product->slug = 'audit-simple-product';
        $product->sku = 'AUDIT-SIMPLE-1';
        $product->has_variations = false;
        $product->save();

        foreach ($this->product->prices()->whereNull('variation_id')->get() as $price) {
            $copy = $price->replicate();
            $copy->product_id = $product->id;
            $copy->save();
        }

        $product->variations()->update(['stock_quantity' => 500]);

        return $product->fresh();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function twoVariationIds(): array
    {
        return $this->product->variations()->orderBy('id')->take(2)->pluck('id')->all();
    }

    private function placeCodOrder(): Testable
    {
        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Boutique Buyer')
            ->set('recipientPhone', '01711000000')
            ->set('streetAddress', 'House 1, Road 2, Gulshan')
            ->set('city', 'Dhaka')
            ->call('placeOrder');
    }

    private function quoteFor(Product $product, int $quantity = 60, float $price = 100.00): RfqRequest
    {
        return RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $product->seller_id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => $quantity,
            'target_unit_price' => $price - 10,
            'seller_quoted_price' => $price,
            'destination_district' => 'Dhaka',
            'status' => 'quoted',
        ]);
    }

    /**
     * WA-PRC-01 (fixed): a quoted line has its own cart line, so a later normal add of the same product gets its own
     * line at the catalog tier price and still has to meet the MOQ on its own.
     */
    public function test_wa_prc_01_normal_add_does_not_merge_into_the_quoted_line(): void
    {
        $simple = $this->makeSimpleProduct();

        $this->cart->addItem($simple->id, null, SalesChannel::WHOLESALE, 40, 70.0, rfqId: 7);

        try {
            $this->cart->addItem($simple->id, null, SalesChannel::WHOLESALE, 10);
            $this->fail('10 normal units were accepted below the MOQ of 50.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('minimum order quantity', $e->getMessage());
        }

        $this->cart->addItem($simple->id, null, SalesChannel::WHOLESALE, 60);

        $lines = collect($this->cart->getItems())->where('product_id', $simple->id);
        $this->assertCount(2, $lines);
        $quoted = $lines->firstWhere('is_quoted', true);
        $normal = $lines->firstWhere('is_quoted', false);
        $this->assertSame([40, 70.0, 7], [$quoted['quantity'], (float) $quoted['unit_price'], $quoted['rfq_id']]);
        $this->assertSame([60, 110.0], [$normal['quantity'], (float) $normal['unit_price']]);
    }

    /**
     * WA-PRC-01 (fixed, carts from before the deploy): a quoted line stored under the normal key is moved to its own
     * quoted key when the cart is read, so a normal add no longer inherits the quoted price.
     */
    public function test_wa_prc_01_quoted_line_from_an_old_cart_is_moved_off_the_normal_key(): void
    {
        $simple = $this->makeSimpleProduct();
        $this->cart->addItem($simple->id, null, SalesChannel::WHOLESALE, 40, 70.0, rfqId: 7);

        // Rewrite the session the way the old code stored the line: under the normal key, with no RFQ id
        $line = collect($this->cart->getItems())->sole();
        $legacyKey = "{$simple->id}_{$line['variation_id']}_".SalesChannel::WHOLESALE;
        unset($line['rfq_id']);
        session()->put('b2bhaat_cart_items', [$legacyKey => [...$line, 'key' => $legacyKey]]);

        $this->cart->addItem($simple->id, null, SalesChannel::WHOLESALE, 60);

        $lines = collect($this->cart->getItems());
        $this->assertCount(2, $lines);
        $this->assertSame(70.0, (float) $lines->firstWhere('is_quoted', true)['unit_price']);
        $this->assertNull($lines->firstWhere('is_quoted', true)['rfq_id']);
        $this->assertSame(110.0, (float) $lines->firstWhere('is_quoted', false)['unit_price']);
        $this->assertSame($legacyKey, $lines->firstWhere('is_quoted', false)['key']);
    }

    /**
     * WA-PRC-01 (fixed): the accepted quote is linked to the order that used it.
     */
    public function test_wa_prc_01_accepted_quote_is_linked_to_its_order(): void
    {
        $rfq = $this->quoteFor($this->makeSimpleProduct());

        $this->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect(route('checkout.index'));
        $this->placeCodOrder()->assertSet('errorMessage', '');

        $order = Order::where('customer_id', $this->buyer->id)->latest('id')->firstOrFail();
        $this->assertSame($order->id, $rfq->fresh()->order_id);
        $this->assertSame(100.0, (float) OrderItem::whereIn('sub_order_id', $order->subOrders->pluck('id'))->sole()->unit_price);
    }

    /**
     * WA-PRC-03: current behaviour; the fix inverts this assertion.
     * Accepting a quote on a product with options adds a line with no option, so the order line has no
     * variation and no stock is reserved.
     */
    public function test_wa_prc_03_quote_on_product_with_options_orders_no_option_and_reserves_no_stock(): void
    {
        $seller = $this->product->seller;
        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 60,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 100.00,
            'destination_district' => 'Dhaka',
            'status' => 'quoted',
        ]);

        $stockBefore = (int) ProductVariation::where('product_id', $this->product->id)->sum('stock_quantity');

        $this->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect(route('checkout.index'));
        $this->placeCodOrder()->assertSet('errorMessage', '');

        $order = Order::where('customer_id', $this->buyer->id)->latest('id')->firstOrFail();
        $item = OrderItem::whereIn('sub_order_id', $order->subOrders->pluck('id'))->sole();
        $this->assertNull($item->variation_id, 'today: the seller is not told which option to ship');
        $this->assertSame(60, $item->quantity);
        $this->assertSame($stockBefore, (int) ProductVariation::where('product_id', $this->product->id)->sum('stock_quantity'), 'today: no stock is reserved for the quoted units');
    }

    /**
     * WA-PRC-02: current behaviour; the fix inverts this assertion.
     * Wholesale access is checked only when the line is added. A buyer whose approval is withdrawn afterwards
     * still checks out at wholesale prices.
     */
    public function test_wa_prc_02_wholesale_lines_are_honoured_after_approval_is_withdrawn(): void
    {
        [$v1, $v2] = $this->twoVariationIds();
        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->set("matrixQuantities.{$v1}", 30)
            ->set("matrixQuantities.{$v2}", 20)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');

        $this->buyer->forceFill(['is_wholesale_approved' => false, 'wholesale_rejected_at' => now()])->save();
        $this->actingAs($this->buyer->fresh());
        $this->assertFalse($this->buyer->fresh()->canSeeWholesalePrices());

        $this->placeCodOrder()->assertSet('errorMessage', '');

        $order = Order::where('customer_id', $this->buyer->id)->latest('id')->firstOrFail();
        $items = $order->subOrders->flatMap->items;
        $this->assertSame(50, (int) $items->sum('quantity'));
        $this->assertEquals(110.0, (float) $items->first()->unit_price, 'today: wholesale ৳110 charged to a buyer no longer approved');
        $this->assertSame(
            SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id'),
            (int) $items->first()->sales_channel_id
        );
    }

    /**
     * WA-PRC-04 (fixed): the quote is marked accepted only after it is in the cart. If the add fails (product hidden
     * meanwhile), the buyer is told why and the quote stays open.
     */
    public function test_wa_prc_04_failed_cart_add_leaves_the_quote_open(): void
    {
        $rfq = $this->quoteFor($this->product);
        $this->product->forceFill(['is_active' => false])->save();

        $this->from(route('customer.rfqs'))
            ->post(route('rfq.quotation.accept', $rfq->id))
            ->assertRedirect(route('customer.rfqs'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'no longer available'));

        $this->assertSame('quoted', $rfq->fresh()->status);
        $this->assertSame([], $this->cart->getItems());
    }

    /**
     * Verified correct: the lot price changes exactly at each tier threshold (D7).
     */
    public function test_verified_lot_tier_boundaries(): void
    {
        [$v1, $v2] = $this->twoVariationIds();
        $expected = [50 => 110.0, 99 => 110.0, 100 => 95.0, 249 => 95.0, 250 => 85.0, 251 => 85.0];

        foreach ($expected as $lot => $price) {
            $this->cart->clear();
            $this->cart->addWholesaleLot($this->product->id, [$v1 => $lot - 20, $v2 => 20]);
            $line = $this->cart->getItems()["{$this->product->id}_{$v1}_".SalesChannel::WHOLESALE];
            $this->assertEquals($price, $line['unit_price'], "lot of {$lot}");
            $this->assertEqualsWithDelta($lot * $price, $this->cart->getSubtotal(), 0.001, "subtotal at lot {$lot}");
        }

        $this->cart->clear();
        $this->expectExceptionMessage('minimum order quantity');
        $this->cart->addWholesaleLot($this->product->id, [$v1 => 29, $v2 => 20]);
    }
}
