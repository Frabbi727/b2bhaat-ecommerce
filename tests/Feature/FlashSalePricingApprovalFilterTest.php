<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Services\Promotions\FlashSalePricing;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSalePricingApprovalFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_pending_or_rejected_submission_does_not_apply_flash_price(): void
    {
        $campaign = FlashSale::factory()->live()->create();
        $product = Product::query()->firstOrFail();

        $offer = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $product->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'status' => FlashSaleSubmissionStatus::Pending,
        ]);

        $pricing = app(FlashSalePricing::class);

        // FlashSalePricing::offerFor($product) must return null for pending
        $this->assertNull($pricing->offerFor($product));

        // Update status to Rejected -> offerFor must return null
        $offer->update(['status' => FlashSaleSubmissionStatus::Rejected]);
        $this->assertNull(app(FlashSalePricing::class)->offerFor($product));

        // Update status to Approved -> offerFor must return the offer!
        $offer->update(['status' => FlashSaleSubmissionStatus::Approved]);
        $activeOffer = app(FlashSalePricing::class)->offerFor($product);

        $this->assertNotNull($activeOffer);
        $this->assertSame($offer->id, $activeOffer->id);
    }
}
