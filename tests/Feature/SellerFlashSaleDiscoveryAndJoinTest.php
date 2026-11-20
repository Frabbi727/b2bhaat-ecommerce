<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Filament\Seller\Resources\FlashSales\FlashSaleResource;
use App\Filament\Seller\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Seller\Resources\FlashSales\Pages\ViewFlashSale;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SellerFlashSaleDiscoveryAndJoinTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->product = Product::where('seller_id', $this->seller->id)->where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        // Ensure retail price is set
        ProductPrice::where('product_id', $this->product->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
    }

    public function test_seller_can_see_campaigns_where_registration_is_open_or_scheduled(): void
    {
        $openCampaign = FlashSale::create([
            'name' => '11.11 Open Campaign',
            'slug' => '11-11-open-campaign',
            'description' => 'Big promotion',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
        ]);

        $activeCampaign = FlashSale::create([
            'name' => 'Active Flash Campaign',
            'slug' => 'active-flash-campaign',
            'description' => 'Ongoing promotion',
            'status' => FlashSaleStatus::Active,
            'registration_starts_at' => Carbon::now()->subDays(2),
            'registration_ends_at' => Carbon::now()->addDay(),
            'starts_at' => Carbon::now()->subHours(2),
            'ends_at' => Carbon::now()->addDays(2),
            'min_discount_percentage' => 15,
            'max_discount_percentage' => 60,
        ]);

        $draftCampaign = FlashSale::create([
            'name' => 'Secret Draft Campaign',
            'slug' => 'secret-draft-campaign',
            'description' => 'Not published',
            'status' => FlashSaleStatus::Draft,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
        ]);

        $cancelledCampaign = FlashSale::create([
            'name' => 'Cancelled Campaign',
            'slug' => 'cancelled-campaign',
            'description' => 'Cancelled',
            'status' => FlashSaleStatus::Cancelled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
        ]);

        Livewire::test(ListFlashSales::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$openCampaign, $activeCampaign])
            ->assertCanNotSeeTableRecords([$draftCampaign, $cancelledCampaign]);
    }

    public function test_seller_can_join_campaign_by_submitting_their_own_product_via_table_action(): void
    {
        $openCampaign = FlashSale::create([
            'name' => 'Black Friday Open Registration',
            'slug' => 'black-friday-open',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
            'min_stock_per_product' => 1,
            'max_stock_per_product' => 100,
            'requires_approval' => true,
        ]);

        $closedCampaign = FlashSale::create([
            'name' => 'Past Registration Campaign',
            'slug' => 'past-registration',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDays(5),
            'registration_ends_at' => Carbon::now()->subDay(),
            'starts_at' => Carbon::now()->addDays(1),
            'ends_at' => Carbon::now()->addDays(3),
        ]);

        Livewire::test(ListFlashSales::class)
            ->assertActionVisible(TestAction::make('submitProduct')->table($openCampaign))
            ->assertActionHidden(TestAction::make('submitProduct')->table($closedCampaign));
    }

    public function test_submitting_product_calls_submission_service_and_creates_flash_sale_product_pending(): void
    {
        $campaign = FlashSale::create([
            'name' => 'Mega Winter Sale',
            'slug' => 'mega-winter-sale',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
            'min_stock_per_product' => 5,
            'max_stock_per_product' => 50,
            'requires_approval' => true,
        ]);

        Livewire::test(ListFlashSales::class)
            ->callAction(TestAction::make('submitProduct')->table($campaign), [
                'product_id' => $this->product->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 20,
                'stock_limit' => 10,
                'per_customer_limit' => 2,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('flash_sale_products', [
            'flash_sale_id' => $campaign->id,
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending->value,
            'discount_type' => FlashSaleDiscountType::Percentage->value,
            'discount_value' => 20.00,
            'original_retail_price' => 1000.00,
            'campaign_price' => 800.00,
            'stock_limit' => 10,
            'per_customer_limit' => 2,
        ]);
    }

    public function test_submitting_invalid_product_data_fails_gracefully_with_notification(): void
    {
        $campaign = FlashSale::create([
            'name' => 'Strict Discount Campaign',
            'slug' => 'strict-discount-campaign',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 20,
            'max_discount_percentage' => 50,
            'min_stock_per_product' => 1,
            'max_stock_per_product' => 100,
            'requires_approval' => true,
        ]);

        Livewire::test(ListFlashSales::class)
            ->callAction(TestAction::make('submitProduct')->table($campaign), [
                'product_id' => $this->product->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 5,
                'stock_limit' => 5,
                'per_customer_limit' => 1,
            ])
            ->assertNotified('Submission failed');

        $this->assertDatabaseMissing('flash_sale_products', [
            'flash_sale_id' => $campaign->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_modal_product_selection_only_lists_authenticated_sellers_products(): void
    {
        $campaign = FlashSale::create([
            'name' => 'Categorized Sale',
            'slug' => 'categorized-sale',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
            'requires_approval' => true,
        ]);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $otherSeller = User::factory()->create([
            'name' => 'Other Merchant',
            'email' => 'other-merchant@factory.com',
            'role_id' => $sellerRole->id,
        ]);

        $otherProduct = Product::create([
            'seller_id' => $otherSeller->id,
            'category_id' => $this->product->category_id,
            'sales_channel_id' => $this->product->sales_channel_id,
            'unit_id' => $this->product->unit_id,
            'title' => 'Other Merchant Unique Gadget',
            'slug' => 'other-merchant-unique-gadget',
            'buy_price' => 100.00,
            'is_active' => true,
        ]);

        Livewire::test(ListFlashSales::class)
            ->mountAction(TestAction::make('submitProduct')->table($campaign))
            ->assertMountedActionModalSee($this->product->title)
            ->assertMountedActionModalDontSee($otherProduct->title);
    }

    public function test_seller_can_view_campaign_details_page(): void
    {
        $campaign = FlashSale::create([
            'name' => 'Viewable Campaign Details',
            'slug' => 'viewable-campaign-details',
            'description' => 'Detailed view campaign test',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 40,
        ]);

        Livewire::test(ViewFlashSale::class, ['record' => $campaign->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Viewable Campaign Details');
    }

    public function test_submission_form_resolves_product_price_and_stock_for_preview(): void
    {
        $campaign = FlashSale::create([
            'name' => 'Preview Test Campaign',
            'slug' => 'preview-test-campaign',
            'status' => FlashSaleStatus::Scheduled,
            'registration_starts_at' => Carbon::now()->subDay(),
            'registration_ends_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(5),
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
            'min_stock_per_product' => 1,
            'max_stock_per_product' => 100,
        ]);

        $retail = FlashSaleResource::resolveProductRetailPrice($this->product);
        $this->assertEquals(1000.00, $retail);

        $stock = FlashSaleResource::resolveProductStock($this->product);
        $this->assertGreaterThanOrEqual(1, $stock);

        $form = FlashSaleResource::getProductSubmissionForm($campaign);
        $componentNames = array_map(fn ($c) => $c->getName(), $form);

        $this->assertContains('product_id', $componentNames);
        $this->assertContains('product_details', $componentNames);
        $this->assertContains('discount_type', $componentNames);
        $this->assertContains('discount_value', $componentNames);
        $this->assertContains('price_preview', $componentNames);
        $this->assertContains('stock_limit', $componentNames);
    }
}
