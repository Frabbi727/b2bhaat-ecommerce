<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Filament\Admin\Resources\FlashSales\Pages\CreateFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\ProductsRelationManager;
use App\Filament\Admin\Resources\FlashSales\Widgets\FlashSaleStatsOverview;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFlashSaleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $customer;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        ProductPrice::where('product_id', $this->nails->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($channel) => $channel->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_creates_a_draft_campaign_and_adds_a_product_with_a_price_preview(): void
    {
        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'Winter Clearance',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'is_featured' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = FlashSale::where('name', 'Winter Clearance')->firstOrFail();
        $this->assertSame(FlashSaleStatus::Draft, $sale->status);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $sale,
            'pageClass' => EditFlashSale::class,
        ])
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => $this->nails->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 20,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertSee('800');

        $this->assertDatabaseHas('flash_sale_products', [
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
            'discount_value' => 20,
        ]);
    }

    public function test_impossible_discounts_are_explained(): void
    {
        $sale = FlashSale::factory()->draft()->create();

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $sale,
            'pageClass' => EditFlashSale::class,
        ])
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => $this->nails->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 100,
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['discount_value']);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $sale,
            'pageClass' => EditFlashSale::class,
        ])
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => $this->nails->id,
                'discount_type' => FlashSaleDiscountType::FixedPrice->value,
                'discount_value' => 1200,
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['discount_value']);
    }

    public function test_start_time_is_entered_and_shown_in_dhaka_time(): void
    {
        // 10:00 AM Dhaka is 04:00 AM UTC
        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'Dhaka Time Test',
                'starts_at' => '2026-12-10 10:00:00',
                'ends_at' => '2026-12-10 12:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = FlashSale::where('name', 'Dhaka Time Test')->firstOrFail();
        $this->assertSame('2026-12-10 04:00:00', $sale->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_turn_off_now_action_ends_a_live_campaign(): void
    {
        $sale = FlashSale::factory()->live()->create();

        Livewire::test(ListFlashSales::class)
            ->callAction(TestAction::make('endNow')->table($sale));

        $this->assertSame(FlashSaleStatus::Ended, $sale->fresh()->status);
        $this->assertFalse($sale->fresh()->isLive());
    }

    public function test_pause_and_resume_actions(): void
    {
        $sale = FlashSale::factory()->live()->create();

        Livewire::test(ListFlashSales::class)
            ->callAction(TestAction::make('pause')->table($sale));

        $this->assertSame(FlashSaleStatus::Paused, $sale->fresh()->status);

        Livewire::test(ListFlashSales::class)
            ->callAction(TestAction::make('resume')->table($sale));

        $this->assertSame(FlashSaleStatus::Scheduled, $sale->fresh()->status);
        $this->assertTrue($sale->fresh()->isLive());
    }

    public function test_delete_is_only_offered_for_drafts(): void
    {
        $draft = FlashSale::factory()->draft()->create();
        $live = FlashSale::factory()->live()->create();

        Livewire::test(ListFlashSales::class)
            ->assertActionVisible(TestAction::make(DeleteAction::class)->table($draft))
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($live));
    }

    public function test_adding_a_product_already_in_an_overlapping_campaign_is_refused(): void
    {
        $sale1 = FlashSale::factory()->live()->create([
            'starts_at' => Carbon::parse('2026-11-10 10:00'),
            'ends_at' => Carbon::parse('2026-11-10 14:00'),
        ]);

        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale1->id,
            'product_id' => $this->nails->id,
        ]);

        $sale2 = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => Carbon::parse('2026-11-10 12:00'),
            'ends_at' => Carbon::parse('2026-11-10 16:00'),
        ]);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $sale2,
            'pageClass' => EditFlashSale::class,
        ])
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => $this->nails->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 10,
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['recordId']);
    }

    public function test_seller_and_customer_cannot_open_the_flash_sales_admin(): void
    {
        $this->actingAs($this->seller);
        $this->get('/admin/flash-sales')->assertForbidden();

        $this->actingAs($this->customer);
        $this->get('/admin/flash-sales')->assertForbidden();
    }

    public function test_admin_can_save_already_started_campaign_without_triggering_start_time_error(): void
    {
        $sale = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(2),
            'name' => 'Original Name',
        ]);

        Livewire::test(EditFlashSale::class, ['record' => $sale->id])
            ->fillForm([
                'name' => 'Updated Name While Active',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name While Active', $sale->fresh()->name);
    }

    public function test_stats_widget_counts_campaigns_by_effective_status(): void
    {
        Livewire::test(FlashSaleStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('Active Campaigns')
            ->assertSee('Scheduled');
    }
}
