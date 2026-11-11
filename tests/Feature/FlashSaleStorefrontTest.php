<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        ProductPrice::where('product_id', $this->nails->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($channel) => $channel->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000]);
    }

    public function test_homepage_shows_the_top_priority_live_campaign_with_its_own_end_time(): void
    {
        // Lower priority campaign
        FlashSale::factory()->live()->create([
            'name' => 'Low Priority Sale',
            'is_featured' => false,
            'display_priority' => 1,
            'ends_at' => now()->addHours(5),
        ]);

        // Top priority featured campaign
        $topSale = FlashSale::factory()->live()->create([
            'name' => 'Top Featured Mega Sale',
            'is_featured' => true,
            'display_priority' => 10,
            'ends_at' => now()->addHours(2),
        ]);

        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $topSale->id,
            'product_id' => $this->nails->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 25,
        ]);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee($topSale->name)
            ->assertSee('data-ends-at="'.$topSale->ends_at->getTimestampMs().'"', false);
    }

    public function test_paused_and_ended_campaigns_are_not_shown_as_live(): void
    {
        FlashSale::query()->update(['status' => FlashSaleStatus::Ended]);

        FlashSale::factory()->create([
            'status' => FlashSaleStatus::Paused,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $response = $this->get(route('catalog.index'))->assertOk();
        $response->assertDontSee('Live Now');
        $response->assertDontSee('data-ends-at');
    }

    public function test_flash_sale_index_lists_live_then_upcoming(): void
    {
        FlashSale::query()->delete();

        $live = FlashSale::factory()->live()->create(['name' => 'Currently Live Sale']);
        $upcoming = FlashSale::factory()->upcoming()->create(['name' => 'Next Week Mega Deal']);
        $paused = FlashSale::factory()->paused()->create(['name' => 'Temporarily Paused']);
        $ended = FlashSale::factory()->ended()->create(['name' => 'Finished Sale']);

        $this->get(route('flash-sales.index'))
            ->assertOk()
            ->assertSee('Currently Live Sale')
            ->assertSee('Next Week Mega Deal')
            ->assertDontSee('Temporarily Paused')
            ->assertDontSee('Finished Sale');
    }

    public function test_campaign_page_states(): void
    {
        $live = FlashSale::factory()->live()->create(['slug' => 'live-campaign']);
        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $live->id,
            'product_id' => $this->nails->id,
        ]);

        $upcoming = FlashSale::factory()->upcoming()->create(['slug' => 'upcoming-campaign']);
        $ended = FlashSale::factory()->ended()->create(['slug' => 'ended-campaign']);
        $draft = FlashSale::factory()->draft()->create(['slug' => 'draft-campaign']);

        // Live: 200 and product list
        $this->get(route('flash-sales.show', 'live-campaign'))
            ->assertOk()
            ->assertSee($this->nails->title);

        // Upcoming: 200 and 'Starts in'
        $this->get(route('flash-sales.show', 'upcoming-campaign'))
            ->assertOk()
            ->assertSee('Starts in:');

        // Ended: 200 and 'ended' notice with link
        $this->get(route('flash-sales.show', 'ended-campaign'))
            ->assertOk()
            ->assertSee('This flash sale has ended');

        // Draft: 404
        $this->get(route('flash-sales.show', 'draft-campaign'))
            ->assertNotFound();
    }

    public function test_countdown_carries_server_time(): void
    {
        $sale = FlashSale::factory()->live()->create();
        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
        ]);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('data-server-now', false);
    }

    public function test_homepage_hides_a_live_sale_that_has_no_approved_deals(): void
    {
        FlashSale::query()->delete();

        $sale = FlashSale::factory()->live()->create(['name' => 'Pending Approval Campaign']);
        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
            'status' => FlashSaleSubmissionStatus::Pending,
        ]);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertDontSee('Pending Approval Campaign');
    }

    public function test_remaining_units_shown_when_stock_limit_set(): void
    {
        FlashSale::query()->delete();

        $sale = FlashSale::factory()->live()->create([
            'is_featured' => true,
            'display_priority' => 10,
        ]);

        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
            'stock_limit' => 10,
        ]);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('10 left');
    }

    public function test_product_page_shows_limit_and_campaign_link(): void
    {
        FlashSale::query()->delete();

        $sale = FlashSale::factory()->live()->create(['name' => 'Exclusive Weekend Deal']);
        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
            'stock_limit' => 15,
            'per_customer_limit' => 2,
        ]);

        $this->get(route('catalog.show', $this->nails->slug))
            ->assertOk()
            ->assertSee('Exclusive Weekend Deal')
            ->assertSee('Limit 2 per customer')
            ->assertSee('15 left');
    }
}
