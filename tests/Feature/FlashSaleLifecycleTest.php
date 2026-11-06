<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Promotions\FlashSaleConflictChecker;
use App\Services\Promotions\FlashSaleLifecycleService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FlashSaleLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->travelTo('2026-11-10 09:00:00');
        $this->lifecycle = app(FlashSaleLifecycleService::class);
    }

    public function test_publish_needs_a_product_and_a_future_end(): void
    {
        $empty = FlashSale::factory()->draft()->create();
        $this->assertRefused('Add at least one product before publishing.', fn () => $this->lifecycle->publish($empty));

        $late = FlashSale::factory()->draft()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $late->id]);
        $this->assertRefused('The end time has already passed.', fn () => $this->lifecycle->publish($late));

        $ok = FlashSale::factory()->draft()->create();
        FlashSaleProduct::factory()->create(['flash_sale_id' => $ok->id]);
        $this->assertSame(FlashSaleStatus::Scheduled, $this->lifecycle->publish($ok)->status);
    }

    public function test_publish_is_refused_when_a_product_is_in_an_overlapping_campaign(): void
    {
        $other = FlashSale::factory()->upcoming()->create(['name' => 'Eid Mega Sale']);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $other->id]);
        $title = Product::query()->orderBy('id')->firstOrFail()->title;

        $draft = FlashSale::factory()->draft()->create(['starts_at' => now()->addDay()->addHour(), 'ends_at' => now()->addDays(3)]);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $draft->id]);

        try {
            $this->lifecycle->publish($draft);
            $this->fail('Expected a refusal.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString($title, $e->getMessage());
            $this->assertStringContainsString('Eid Mega Sale', $e->getMessage());
        }
        $this->assertSame(FlashSaleStatus::Draft, $draft->fresh()->status);
    }

    public function test_back_to_back_campaigns_do_not_conflict(): void
    {
        $first = FlashSale::factory()->upcoming()->create([
            'starts_at' => '2026-11-11 10:00', 'ends_at' => '2026-11-11 12:00',
        ]);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $first->id]);

        $second = FlashSale::factory()->draft()->create([
            'starts_at' => '2026-11-11 12:00', 'ends_at' => '2026-11-11 14:00',
        ]);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $second->id]);

        $this->assertCount(0, app(FlashSaleConflictChecker::class)->conflicts($second));
        $this->assertSame(FlashSaleStatus::Scheduled, $this->lifecycle->publish($second)->status);
    }

    public function test_pause_stops_the_price_at_once_and_resume_restores_it(): void
    {
        $sale = FlashSale::factory()->live()->create();
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id]);
        $this->assertTrue($sale->isLive());

        $paused = $this->lifecycle->pause($sale);
        $this->assertFalse($paused->isLive());
        $this->assertNotNull($paused->paused_at);

        $resumed = $this->lifecycle->resume($paused);
        $this->assertTrue($resumed->isLive());
        $this->assertNull($resumed->paused_at);
        $this->assertSame(FlashSaleStatus::Scheduled, $resumed->status);
    }

    public function test_resume_after_the_end_time_is_refused(): void
    {
        $sale = FlashSale::factory()->paused()->create();
        $this->travelTo(now()->addHours(3));

        $this->assertRefused('ended', fn () => $this->lifecycle->resume($sale));
        $this->assertSame(FlashSaleStatus::Paused, $sale->fresh()->status);
    }

    public function test_end_now_turns_off_a_live_campaign_immediately(): void
    {
        $sale = FlashSale::factory()->live()->create();

        $ended = $this->lifecycle->endNow($sale);

        $this->assertSame(FlashSaleStatus::Ended, $ended->status);
        $this->assertTrue($ended->ended_at->equalTo(now()));
        $this->assertFalse($ended->isLive());
    }

    public function test_cancel_is_refused_once_units_are_sold(): void
    {
        $sale = FlashSale::factory()->live()->create();
        $offer = FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id]);
        $this->placeItem($offer, 2);

        $this->assertRefused('Turn off now', fn () => $this->lifecycle->cancel($sale));

        $this->assertRefused('Turn off now', fn () => $this->lifecycle->cancel($this->lifecycle->pause($sale)));
        $this->assertSame(FlashSaleStatus::Paused, $sale->fresh()->status);
    }

    public function test_cancel_works_for_drafts_and_campaigns_before_start(): void
    {
        $draft = FlashSale::factory()->draft()->create();
        $upcoming = FlashSale::factory()->upcoming()->create();

        $this->assertSame(FlashSaleStatus::Cancelled, $this->lifecycle->cancel($draft)->status);
        $this->assertSame(FlashSaleStatus::Cancelled, $this->lifecycle->cancel($upcoming)->status);
        $this->assertRefused('Turn off now', fn () => $this->lifecycle->cancel(FlashSale::factory()->live()->create()));
    }

    public function test_start_now_brings_the_start_time_forward(): void
    {
        $sale = FlashSale::factory()->upcoming()->create();

        $started = $this->lifecycle->startNow($sale);

        $this->assertTrue($started->starts_at->equalTo(now()));
        $this->assertTrue($started->isLive());
        $this->assertRefused('already started', fn () => $this->lifecycle->startNow($started));
    }

    public function test_only_drafts_can_be_deleted(): void
    {
        $draft = FlashSale::factory()->draft()->create();
        $this->lifecycle->delete($draft);
        $this->assertModelMissing($draft);

        $upcoming = FlashSale::factory()->upcoming()->create();
        $this->assertRefused('Only drafts', fn () => $this->lifecycle->delete($upcoming));
        $this->assertModelExists($upcoming);
    }

    public function test_duplicate_creates_a_draft_with_the_same_products(): void
    {
        $sale = FlashSale::factory()->ended()->create(['name' => 'Winter Sale']);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'discount_value' => 25, 'stock_limit' => 40]);

        $copy = $this->lifecycle->duplicate($sale);

        $this->assertSame('Winter Sale (copy)', $copy->name);
        $this->assertNotSame($sale->slug, $copy->slug);
        $this->assertSame(FlashSaleStatus::Draft, $copy->status);
        $this->assertTrue($copy->starts_at->equalTo($sale->starts_at));
        $this->assertNull($copy->ended_at);
        $this->assertCount(1, $copy->items);
        $this->assertSame(40, $copy->items->first()->stock_limit);
        $this->assertSame('25.00', $copy->items->first()->discount_value);
    }

    public function test_end_time_cannot_be_moved_into_the_past_by_editing(): void
    {
        $sale = FlashSale::factory()->live()->create();

        $this->assertRefused('Turn off now', fn () => $this->lifecycle->assertEditable($sale, ['ends_at' => now()->subMinute()]));

        $this->lifecycle->assertEditable($sale, ['ends_at' => now()->addDay()]);
    }

    public function test_start_time_cannot_change_once_started(): void
    {
        $sale = FlashSale::factory()->live()->create();
        $this->assertRefused('start time', fn () => $this->lifecycle->assertEditable($sale, ['starts_at' => now()->subDay()]));
        $this->lifecycle->assertEditable($sale, ['starts_at' => $sale->starts_at, 'name' => 'New name']);

        $upcoming = FlashSale::factory()->upcoming()->create();
        $this->lifecycle->assertEditable($upcoming, ['starts_at' => now()->addHours(5)]);

        $ended = FlashSale::factory()->ended()->create();
        $this->assertRefused('cannot be edited', fn () => $this->lifecycle->assertEditable($ended, ['name' => 'x']));
    }

    public function test_stock_limit_cannot_go_below_units_already_sold(): void
    {
        $sale = FlashSale::factory()->live()->create();
        $offer = FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'stock_limit' => 50]);
        $this->placeItem($offer, 6);

        $this->assertRefused('6', fn () => $this->lifecycle->assertStockLimitAllowed($offer, 5));
        $this->lifecycle->assertStockLimitAllowed($offer, 6);
        $this->lifecycle->assertStockLimitAllowed($offer, null);

        $this->assertRefused('6', fn () => $this->lifecycle->assertEditable($sale, [
            'items' => [['flash_sale_product_id' => $offer->id, 'stock_limit' => 3]],
        ]));
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function assertRefused(string $messagePart, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected InvalidStatusTransitionException.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    private function placeItem(FlashSaleProduct $offer, int $quantity): void
    {
        $customer = User::factory()->create();
        $order = Order::create([
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::query()->value('id'),
            'payment_status_id' => PaymentStatus::query()->value('id'),
            'payment_method_id' => PaymentMethod::query()->value('id'),
            'shipping_address' => 'Dhaka',
            'total_amount' => 100,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ]);

        $product = $offer->product;
        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $product->seller_id,
            'sub_order_number' => $order->order_number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id'),
            'subtotal' => 100,
            'commission_amount' => 10,
            'shipping_amount' => 0,
        ]);

        OrderItem::create([
            'sub_order_id' => $subOrder->id,
            'product_id' => $product->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'flash_sale_product_id' => $offer->id,
            'unit_price' => 25,
            'original_unit_price' => 50,
            'quantity' => $quantity,
            'total_price' => 25 * $quantity,
        ]);
    }
}
