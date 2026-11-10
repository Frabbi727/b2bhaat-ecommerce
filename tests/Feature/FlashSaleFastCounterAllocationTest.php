<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Exceptions\FlashSaleUnavailableException;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\FulfillmentStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Orders\OrderDisputeService;
use App\Services\Promotions\FlashSaleAllocationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleFastCounterAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_claim_atomically_increments_units_sold_and_enforces_quota(): void
    {
        $campaign = FlashSale::factory()->live()->create();
        $product = Product::query()->firstOrFail();
        $customer = User::factory()->create();

        $offer = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 15,
            'stock_limit' => 10,
            'units_sold' => 0,
            'status' => FlashSaleSubmissionStatus::Approved,
        ]);

        $service = app(FlashSaleAllocationService::class);

        // Customer claims 6 units via FlashSaleAllocationService::claim().
        $service->claim($customer, [
            [
                'product_id' => $product->id,
                'quantity' => 6,
                'flash_sale_product_id' => $offer->id,
            ],
        ]);

        // Assert $offer->fresh()->units_sold === 6.
        $this->assertSame(6, $offer->fresh()->units_sold);

        // Customer claims 5 units -> throws FlashSaleUnavailableException::onlyLeft(4, ...).
        try {
            $service->claim($customer, [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'flash_sale_product_id' => $offer->id,
                ],
            ]);
            $this->fail('Expected FlashSaleUnavailableException was not thrown.');
        } catch (FlashSaleUnavailableException $e) {
            $this->assertStringContainsString('4 left', $e->getMessage());
        }

        // Units sold should remain 6 because previous claim failed
        $this->assertSame(6, $offer->fresh()->units_sold);

        // Customer claims 4 units -> succeeds, $offer->fresh()->units_sold === 10.
        $service->claim($customer, [
            [
                'product_id' => $product->id,
                'quantity' => 4,
                'flash_sale_product_id' => $offer->id,
            ],
        ]);
        $this->assertSame(10, $offer->fresh()->units_sold);

        // Next claim of 1 unit -> throws FlashSaleUnavailableException::onlyLeft(0, ...) or ended.
        try {
            $service->claim($customer, [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'flash_sale_product_id' => $offer->id,
                ],
            ]);
            $this->fail('Expected FlashSaleUnavailableException was not thrown on exhausted stock.');
        } catch (FlashSaleUnavailableException $e) {
            $this->assertMatchesRegularExpression('/(0 left|ended)/i', $e->getMessage());
        }
    }

    public function test_claim_rejects_unapproved_or_pending_flash_sale_offer(): void
    {
        $campaign = FlashSale::factory()->live()->create();
        $product = Product::query()->firstOrFail();
        $customer = User::factory()->create();

        $pendingOffer = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 10,
            'stock_limit' => 10,
            'units_sold' => 0,
            'status' => FlashSaleSubmissionStatus::Pending,
        ]);

        $service = app(FlashSaleAllocationService::class);

        $this->expectException(FlashSaleUnavailableException::class);
        $service->claim($customer, [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'flash_sale_product_id' => $pendingOffer->id,
            ],
        ]);
    }

    public function test_cancelling_sub_order_restores_campaign_quota_and_clamps_at_zero(): void
    {
        $campaign = FlashSale::factory()->live()->create();
        $product = Product::query()->firstOrFail();

        $offer = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 15,
            'stock_limit' => 10,
            'units_sold' => 5,
            'status' => FlashSaleSubmissionStatus::Approved,
        ]);

        $subOrder = SubOrder::query()->firstOrFail();
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => $unfulfilledStatus->id]);

        $subOrder->items()->delete();
        OrderItem::create([
            'sub_order_id' => $subOrder->id,
            'product_id' => $product->id,
            'sales_channel_id' => $product->sales_channel_id ?? 1,
            'flash_sale_product_id' => $offer->id,
            'quantity' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $disputeService = app(OrderDisputeService::class);
        $disputeService->cancelSubOrder($subOrder, 'Customer cancelled');

        $this->assertSame(2, $offer->fresh()->units_sold);

        // Cancel a sub-order whose quantity exceeds the remaining units_sold to verify clamping at 0
        $subOrder2 = SubOrder::create([
            'parent_order_id' => $subOrder->parent_order_id,
            'seller_id' => $subOrder->seller_id,
            'sub_order_number' => 'SUB-CLAMP-001',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 50.00,
        ]);

        OrderItem::create([
            'sub_order_id' => $subOrder2->id,
            'product_id' => $product->id,
            'sales_channel_id' => $product->sales_channel_id ?? 1,
            'flash_sale_product_id' => $offer->id,
            'quantity' => 10,
            'unit_price' => 100.00,
            'total_price' => 1000.00,
        ]);

        $disputeService->cancelSubOrder($subOrder2, 'Buyer returned');

        $this->assertSame(0, $offer->fresh()->units_sold);
    }

    /**
     * FS-3: an offer only prices the product it was approved for. A cart line that names another product's
     * offer (stale or tampered) is refused and claims nothing.
     */
    public function test_an_offer_cannot_be_claimed_for_another_product(): void
    {
        $campaign = FlashSale::factory()->live()->create();
        $offered = Product::query()->firstOrFail();
        $other = $offered->replicate();
        $other->forceFill(['title' => 'Other Product', 'slug' => 'other-product-fs3', 'sku' => null])->save();
        $offer = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $offered->id,
            'stock_limit' => 10,
            'units_sold' => 0,
            'status' => FlashSaleSubmissionStatus::Approved,
        ]);

        foreach ([
            [['product_id' => $other->id, 'quantity' => 1, 'flash_sale_product_id' => $offer->id]],
            // A right line cannot carry a wrong one past the check
            [
                ['product_id' => $offered->id, 'quantity' => 1, 'flash_sale_product_id' => $offer->id],
                ['product_id' => $other->id, 'quantity' => 1, 'flash_sale_product_id' => $offer->id],
            ],
        ] as $items) {
            try {
                app(FlashSaleAllocationService::class)->claim(User::factory()->create(), $items);
                $this->fail('A mismatched offer was claimed');
            } catch (FlashSaleUnavailableException $e) {
                $this->assertStringContainsString($other->title, $e->getMessage());
            }
        }

        $this->assertSame(0, $offer->fresh()->units_sold);
    }
}
