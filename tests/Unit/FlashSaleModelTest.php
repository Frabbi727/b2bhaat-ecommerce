<?php

namespace Tests\Unit;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
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
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FlashSaleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_status_follows_the_clock_for_published_campaigns(): void
    {
        $sale = FlashSale::factory()->create([
            'status' => 'scheduled',
            'starts_at' => '2026-11-10 10:00',
            'ends_at' => '2026-11-10 12:00',
        ]);

        $this->assertSame(FlashSaleStatus::Scheduled, $sale->effectiveStatus(Carbon::parse('2026-11-10 09:59')));
        $this->assertSame(FlashSaleStatus::Active, $sale->effectiveStatus(Carbon::parse('2026-11-10 10:00')));
        $this->assertSame(FlashSaleStatus::Ended, $sale->effectiveStatus(Carbon::parse('2026-11-10 12:00')));

        $sale->update(['status' => 'active']);
        $this->assertSame(FlashSaleStatus::Active, $sale->effectiveStatus(Carbon::parse('2026-11-10 11:00')));
    }

    public function test_paused_draft_and_cancelled_are_never_live_even_inside_the_window(): void
    {
        foreach (['draft', 'paused', 'cancelled', 'ended'] as $status) {
            FlashSale::factory()->create([
                'status' => $status,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
            ]);
        }

        foreach (FlashSale::all() as $sale) {
            $this->assertFalse($sale->isLive(), "{$sale->status->value} must not be live");
        }
        $this->assertSame(0, FlashSale::live()->count());

        FlashSale::factory()->live()->create();
        FlashSale::factory()->upcoming()->create();

        $this->assertSame(1, FlashSale::live()->count());
        $this->assertSame(1, FlashSale::upcoming()->count());
    }

    public function test_price_from_percentage_fixed_amount_and_fixed_price(): void
    {
        $this->assertSame(850.0, $this->offer(FlashSaleDiscountType::Percentage, 15)->priceFrom(1000.0));
        $this->assertSame(800.0, $this->offer(FlashSaleDiscountType::FixedAmount, 200)->priceFrom(1000.0));
        $this->assertSame(699.0, $this->offer(FlashSaleDiscountType::FixedPrice, 699)->priceFrom(1000.0));
    }

    public function test_price_never_goes_below_one_paisa_or_above_the_base(): void
    {
        $this->assertSame(0.01, $this->offer(FlashSaleDiscountType::FixedAmount, 5000)->priceFrom(1000.0));
        $this->assertSame(1000.0, $this->offer(FlashSaleDiscountType::FixedPrice, 1500)->priceFrom(1000.0));
    }

    public function test_units_sold_ignores_cancelled_sub_orders(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $product = Product::query()->firstOrFail();
        $offer = FlashSaleProduct::factory()->create(['product_id' => $product->id]);
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();

        $this->placeItem($offer, $buyer, 'unfulfilled', 3);
        $this->placeItem($offer, $otherBuyer, 'cancelled', 2);
        $this->placeItem($offer, $otherBuyer, 'delivered', 4);

        $this->assertSame(7, $offer->unitsSold());
        $this->assertSame(3, $offer->unitsBoughtBy($buyer->id));
        $this->assertSame(4, $offer->unitsBoughtBy($otherBuyer->id));
    }

    private function offer(FlashSaleDiscountType $type, float $value): FlashSaleProduct
    {
        return FlashSaleProduct::factory()->make(['discount_type' => $type, 'discount_value' => $value]);
    }

    private function placeItem(FlashSaleProduct $offer, User $customer, string $fulfillmentSlug, int $quantity): void
    {
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
            'fulfillment_status_id' => FulfillmentStatus::where('slug', $fulfillmentSlug)->value('id'),
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
