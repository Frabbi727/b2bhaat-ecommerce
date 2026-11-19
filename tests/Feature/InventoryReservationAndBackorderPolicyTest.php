<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Livewire\Storefront\ProductDetail;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\StockMovement;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Inventory\StockReservationService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryReservationAndBackorderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const NO_NEGATIVE_STOCK_MIGRATION = '2026_11_19_090000_prevent_negative_stock_on_product_variations.php';

    protected StockReservationService $stockService;

    protected StoreConfigurationService $configService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->stockService = app(StockReservationService::class);
        $this->configService = app(StoreConfigurationService::class);
    }

    public function test_unpaid_pending_orders_expire_and_release_stock_after_ttl_threshold(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $initialStock = $variation->stock_quantity;

        // Reserve 5 units
        $this->stockService->reserveStock([
            ['variation_id' => $variation->id, 'quantity' => 5],
        ]);
        $this->assertEquals($initialStock - 5, $variation->fresh()->stock_quantity);

        $pendingStatus = OrderStatus::where('slug', 'pending')->firstOrFail();
        $unpaidStatus = PaymentStatus::where('slug', 'unpaid')->firstOrFail();
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'sslcommerz')->firstOrFail();

        // Create pending unpaid order that was created 20 minutes ago (exceeding default 15m TTL)
        $order = Order::create([
            'order_number' => 'ORD-EXP-001',
            'customer_id' => $customer->id,
            'order_status_id' => $pendingStatus->id,
            'payment_status_id' => $unpaidStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 2500.00,
        ]);
        $order->timestamps = false;
        $order->created_at = now()->subMinutes(20);
        $order->save();

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-EXP-001-A',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 2500.00,
            'commission_amount' => 250.00,
            'shipping_amount' => 70.00,
        ]);
        $subOrder->timestamps = false;
        $subOrder->created_at = now()->subMinutes(20);
        $subOrder->save();

        OrderItem::create([
            'sub_order_id' => $subOrder->id,
            'product_id' => $variation->product_id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'quantity' => 5,
            'unit_price' => 500.00,
            'total_price' => 2500.00,
        ]);

        // Run expiration cleanup command
        $this->artisan('inventory:release-expired-reservations')
            ->expectsOutputToContain('Expired stock reservation cleanup complete: 1 order(s) cancelled, 5 item unit(s) restored to stock.')
            ->assertExitCode(0);

        // Verify stock is restored
        $this->assertEquals($initialStock, $variation->fresh()->stock_quantity);

        // Verify order and sub-order statuses are cancelled
        $this->assertEquals(OrderStatus::where('slug', 'cancelled')->value('id'), $order->fresh()->order_status_id);
        $this->assertEquals(FulfillmentStatus::where('slug', 'cancelled')->value('id'), $subOrder->fresh()->fulfillment_status_id);

        // Verify tracking log exists
        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Stock Reservation Expired',
        ]);
    }

    public function test_recent_or_paid_orders_are_not_released_prematurely(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $initialStock = $variation->stock_quantity;

        // Reserve 4 units
        $this->stockService->reserveStock([
            ['variation_id' => $variation->id, 'quantity' => 4],
        ]);

        $pendingStatus = OrderStatus::where('slug', 'pending')->firstOrFail();
        $confirmedStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
        $unpaidStatus = PaymentStatus::where('slug', 'unpaid')->firstOrFail();
        $paidStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'sslcommerz')->firstOrFail();

        // Order 1: Recent pending unpaid order (created 5 minutes ago, inside 15m window)
        $recentOrder = Order::create([
            'order_number' => 'ORD-RECENT-001',
            'customer_id' => $customer->id,
            'order_status_id' => $pendingStatus->id,
            'payment_status_id' => $unpaidStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 1000.00,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        $subOrderRecent = SubOrder::create([
            'parent_order_id' => $recentOrder->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-RECENT-001-A',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 70.00,
            'created_at' => now()->subMinutes(5),
        ]);
        OrderItem::create([
            'sub_order_id' => $subOrderRecent->id,
            'product_id' => $variation->product_id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'quantity' => 2,
            'unit_price' => 500.00,
            'total_price' => 1000.00,
        ]);

        // Order 2: Mature order (30m ago) but PAID
        $paidOrder = Order::create([
            'order_number' => 'ORD-PAID-001',
            'customer_id' => $customer->id,
            'order_status_id' => $confirmedStatus->id,
            'payment_status_id' => $paidStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 1000.00,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $subOrderPaid = SubOrder::create([
            'parent_order_id' => $paidOrder->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-PAID-001-A',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 70.00,
            'created_at' => now()->subMinutes(30),
        ]);
        OrderItem::create([
            'sub_order_id' => $subOrderPaid->id,
            'product_id' => $variation->product_id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'quantity' => 2,
            'unit_price' => 500.00,
            'total_price' => 1000.00,
        ]);

        // Run cleanup
        $this->artisan('inventory:release-expired-reservations')
            ->expectsOutputToContain('0 order(s) cancelled, 0 item unit(s) restored to stock.')
            ->assertExitCode(0);

        // Neither order cancelled
        $this->assertEquals($pendingStatus->id, $recentOrder->fresh()->order_status_id);
        $this->assertEquals($confirmedStatus->id, $paidOrder->fresh()->order_status_id);

        // Stock remains reserved (initialStock - 4)
        $this->assertEquals($initialStock - 4, $variation->fresh()->stock_quantity);
    }

    /**
     * AR-09 (owner decision 2026-11-19): no backorders. A shop that had the old "allow orders at 0 stock" setting on
     * still can't sell more than it has.
     */
    public function test_stock_never_goes_below_zero_even_with_the_retired_backorder_setting_on(): void
    {
        $this->configService->set('allow_backorders', true, 'inventory', 'boolean');

        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $variation->update(['stock_quantity' => 2]);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Insufficient stock for SKU');

        try {
            $this->stockService->reserveStock([
                ['variation_id' => $variation->id, 'quantity' => 5],
            ]);
        } finally {
            $this->assertEquals(2, $variation->fresh()->stock_quantity);
        }
    }

    public function test_product_detail_never_adds_more_than_the_stock(): void
    {
        $product = Product::with('variations')->where('has_variations', true)->firstOrFail();
        $variation = $product->variations->firstOrFail();
        $variation->update(['stock_quantity' => 1, 'is_active' => true]);
        $this->configService->set('allow_backorders', true, 'inventory', 'boolean');

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('selectedVariationId', $variation->id)
            ->set('retailQuantity', 5)
            ->call('addToCartRetail')
            ->assertSee('exceeds available stock');
    }

    public function test_the_database_refuses_negative_stock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The stock check constraint is added on PostgreSQL only.');
        }

        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('product_variations')->where('id', $variation->id)->update(['stock_quantity' => -1]);
    }

    public function test_the_migration_resets_negative_stock_to_zero_and_records_it(): void
    {
        $migration = require database_path('migrations/'.self::NO_NEGATIVE_STOCK_MIGRATION);
        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();

        $migration->down();
        DB::table('product_variations')->where('id', $variation->id)->update(['stock_quantity' => -3]);
        $migration->up();

        $this->assertSame(0, (int) $variation->fresh()->stock_quantity);
        $movement = StockMovement::where('product_variation_id', $variation->id)->latest('id')->firstOrFail();
        $this->assertSame(StockMovement::REASON_ADJUSTMENT, $movement->reason);
        $this->assertSame(3, $movement->quantity_change);
        $this->assertSame(0, $movement->quantity_after);
        $this->assertStringContainsString('backorders', (string) $movement->note);
    }
}
