<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\StockMovement;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockBulkUpdateService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Orders\ParcelCancellationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 2.3 (AR-08): every stock change writes a stock_movements row saying how much, why, by whom and for which
 * order, so a variation's stock is always the sum of its movements.
 */
class StockMovementLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private ProductVariation $variation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        Queue::fake();

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->variation = Product::where('seller_id', $this->seller->id)->firstOrFail()->variations()->firstOrFail();
    }

    private function lastMovement(): StockMovement
    {
        return StockMovement::where('product_variation_id', $this->variation->id)->latest('id')->firstOrFail();
    }

    private function placeOrder(int $quantity): Order
    {
        return app(OrderSplitterService::class)->createAndSplitOrder(User::where('email', 'customer@retail.com')->firstOrFail(), [
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
            'shipping_address' => ['recipient_name' => 'Tanvir Hasan', 'street' => 'Dhanmondi, Dhaka', 'phone' => '+8801700000004'],
            'items' => [[
                'product_id' => $this->variation->product_id,
                'variation_id' => $this->variation->id,
                'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
                'quantity' => $quantity,
                'unit_price' => 250.00,
            ]],
        ]);
    }

    private function assertLedgerMatchesStock(): void
    {
        $mismatched = ProductVariation::query()
            ->get()
            ->filter(fn (ProductVariation $variation): bool => (int) StockMovement::where('product_variation_id', $variation->id)->sum('quantity_change') !== (int) $variation->stock_quantity)
            ->pluck('sku');

        $this->assertSame([], $mismatched->all(), 'stock = sum of movements for every variation');
    }

    public function test_placing_an_order_records_a_sale_against_the_order(): void
    {
        $stockBefore = (int) $this->variation->stock_quantity;

        $order = $this->placeOrder(2);

        $movement = $this->lastMovement();
        $this->assertSame(StockMovement::REASON_ORDER_PLACED, $movement->reason);
        $this->assertSame(-2, $movement->quantity_change);
        $this->assertSame($stockBefore - 2, $movement->quantity_after);
        $this->assertTrue($movement->reference->is($order), 'linked to the order that took the stock');
        $this->assertLedgerMatchesStock();
    }

    public function test_cancelling_a_parcel_records_the_units_coming_back(): void
    {
        $order = $this->placeOrder(2);
        $parcel = SubOrder::where('parent_order_id', $order->id)->firstOrFail();

        app(ParcelCancellationService::class)->declineBySeller($parcel, $this->seller, 'Out of stock');

        $movement = $this->lastMovement();
        $this->assertSame(StockMovement::REASON_ORDER_CANCELLED, $movement->reason);
        $this->assertSame(2, $movement->quantity_change);
        $this->assertTrue($movement->reference->is($parcel));
        $this->assertLedgerMatchesStock();
    }

    public function test_seller_adjustment_records_the_reason_note_and_who(): void
    {
        $this->actingAs($this->seller);

        app(StockAdjustmentService::class)->adjust($this->variation, -3, 'damaged', 'Water leak');

        $movement = $this->lastMovement();
        $this->assertSame(StockMovement::REASON_ADJUSTMENT, $movement->reason);
        $this->assertSame(-3, $movement->quantity_change);
        $this->assertSame('Damaged or lost: Water leak', $movement->note);
        $this->assertSame($this->seller->id, $movement->user_id);
        $this->assertLedgerMatchesStock();
    }

    public function test_stock_sheet_upload_records_a_bulk_update(): void
    {
        $this->actingAs($this->seller);

        $target = (int) $this->variation->stock_quantity + 25;

        $result = app(StockBulkUpdateService::class)->importCsvForSeller($this->seller, "sku,new_stock_quantity\n{$this->variation->sku},{$target}\n");
        $this->assertSame(1, $result['updated'], json_encode($result));

        $movement = $this->lastMovement();
        $this->assertSame(StockMovement::REASON_BULK_UPDATE, $movement->reason);
        $this->assertSame($target, $movement->quantity_after);
        $this->assertSame(25, $movement->quantity_change);
        $this->assertLedgerMatchesStock();
    }

    public function test_a_direct_edit_is_recorded_and_an_edit_that_leaves_stock_alone_is_not(): void
    {
        $this->actingAs($this->seller);
        $count = StockMovement::count();

        $this->variation->update(['buy_price' => 12.5]);
        $this->assertSame($count, StockMovement::count(), 'no stock change, no movement');

        $this->variation->update(['stock_quantity' => 77]);
        $movement = $this->lastMovement();
        $this->assertSame(StockMovement::REASON_PRODUCT_EDIT, $movement->reason);
        $this->assertSame(77, $movement->quantity_after);
        $this->assertSame($this->seller->id, $movement->user_id);
        $this->assertLedgerMatchesStock();
    }

    public function test_a_failed_order_leaves_no_movement_behind(): void
    {
        $count = StockMovement::count();

        try {
            DB::transaction(function (): void {
                $this->placeOrder(2);
                throw new \RuntimeException('Payment step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($count, StockMovement::count());
        $this->assertLedgerMatchesStock();
    }

    public function test_seller_sees_the_stock_history_of_a_product(): void
    {
        $order = $this->placeOrder(2);
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListProducts::class)
            ->mountAction(TestAction::make('stockHistory')->table($this->variation->product))
            ->assertMountedActionModalSee(['Order placed', 'Order #'.$order->order_number, '-2']);
    }

    public function test_new_variations_open_with_their_starting_stock(): void
    {
        $this->assertLedgerMatchesStock();
        $this->assertSame(
            ProductVariation::where('stock_quantity', '!=', 0)->count(),
            StockMovement::where('reason', StockMovement::REASON_OPENING)->count(),
        );
    }
}
