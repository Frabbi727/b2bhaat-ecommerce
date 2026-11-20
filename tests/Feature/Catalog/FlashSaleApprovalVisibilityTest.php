<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\FlashSaleDiscountType;
use App\Enums\ProductApprovalStatus;
use App\Filament\Seller\Resources\FlashSales\FlashSaleResource;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Services\Promotions\FlashSaleSubmissionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INV-8 final review: a product enrolled in a flash sale is still only shown, offered and accepted once it is approved.
 */
class FlashSaleApprovalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Product $approved;

    private Product $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
        FlashSale::query()->delete();

        $this->approved = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        ProductPrice::where('product_id', $this->approved->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($channel) => $channel->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000]);

        $this->pending = $this->approved->replicate(['slug']);
        $this->pending->fill(['title' => 'Unreviewed Velvet Cushion', 'slug' => 'unreviewed-velvet-cushion'])->save();
        $this->pending->forceFill(['approval_status' => ProductApprovalStatus::PendingReview->value, 'approved_at' => null])->save();
        $this->pending->prices()->create(['sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'), 'min_quantity' => 1, 'unit_price' => 800]);
        $this->pending->ensureDefaultVariation()->update(['stock_quantity' => 20]);
    }

    private function liveSaleWithBoth(): FlashSale
    {
        $sale = FlashSale::factory()->live()->create(['name' => 'Approval Weekend Sale', 'slug' => 'approval-weekend-sale']);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => $this->approved->id]);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => $this->pending->id]);

        return $sale;
    }

    public function test_the_campaign_page_hides_an_unapproved_enrolled_product(): void
    {
        $this->liveSaleWithBoth();

        $this->get(route('flash-sales.show', 'approval-weekend-sale'))
            ->assertOk()
            ->assertSee($this->approved->title)
            ->assertDontSee('Unreviewed Velvet Cushion');
    }

    public function test_the_flash_sale_index_hides_an_unapproved_enrolled_product(): void
    {
        $this->liveSaleWithBoth();

        $this->get(route('flash-sales.index'))
            ->assertOk()
            ->assertSee('Approval Weekend Sale')
            ->assertSee($this->approved->title)
            ->assertDontSee('Unreviewed Velvet Cushion');
    }

    public function test_submit_refuses_a_product_that_is_not_approved(): void
    {
        $seller = $this->pending->seller;
        $campaign = FlashSale::factory()->create([
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(3),
            'min_discount_percentage' => 5,
            'max_discount_percentage' => 50,
        ]);

        try {
            app(FlashSaleSubmissionService::class)->submit($seller, $campaign, [
                'product_id' => $this->pending->id,
                'discount_type' => FlashSaleDiscountType::Percentage,
                'discount_value' => 20,
                'stock_limit' => 10,
            ]);
            $this->fail('Expected a DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Only approved products can join a flash sale.', $e->getMessage());
        }

        $this->assertFalse(FlashSaleProduct::where('flash_sale_id', $campaign->id)->exists());
    }

    public function test_the_seller_picker_offers_approved_products_only(): void
    {
        $campaign = FlashSale::factory()->create();
        $this->actingAs($this->pending->seller);

        $offered = FlashSaleResource::submittableProductsQuery($campaign)->pluck('id')->all();

        $this->assertContains($this->approved->id, $offered);
        $this->assertNotContains($this->pending->id, $offered);
    }
}
