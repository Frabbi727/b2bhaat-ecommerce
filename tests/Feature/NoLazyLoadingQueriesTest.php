<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ViewAccountingJournalEntry;
use App\Filament\Admin\Resources\FlashSales\Pages\ListFlashSales;
use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\ProductDetail;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PERF-3: busy pages load what they show up front instead of one query per row. Lazy loading is turned into an
 * error here, so any relation fetched row by row fails the test.
 */
class NoLazyLoadingQueriesTest extends TestCase
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

        Model::preventLazyLoading();
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_product_page_and_its_updates_load_everything_up_front(): void
    {
        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail());
        $variationId = $this->nails->variations()->value('id');

        Livewire::test(ProductDetail::class, ['slug' => $this->nails->slug])
            ->assertSuccessful()
            ->set("matrixQuantities.{$variationId}", 10)
            ->call('selectImage', 0)
            ->assertSuccessful();
    }

    public function test_catalog_page_loads_variations_for_featured_and_flash_products(): void
    {
        $this->nails->update(['is_featured' => true]);
        $sale = FlashSale::factory()->live()->create();
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => $this->nails->id]);

        Livewire::test(ProductCatalog::class)->assertSuccessful();
    }

    public function test_flash_sale_list_counts_units_sold_in_one_query_without_cancelled_parcels(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $sale = FlashSale::factory()->create(['name' => 'Units Sale']);
        $offer = FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => $this->nails->id]);
        $second = FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => Product::whereKeyNot($this->nails->id)->value('id')]);

        $item = OrderItem::firstOrFail();
        $item->update(['flash_sale_product_id' => $offer->id, 'quantity' => 4]);
        $this->copyItem($item, $second->id, 3, cancelled: false);
        $this->copyItem($item, $offer->id, 5, cancelled: true);

        $this->assertSame(4, $offer->unitsSold());

        // The old column ran one units-sold query per campaign product
        $perProductQueries = 0;
        DB::listen(function ($query) use (&$perProductQueries): void {
            if (str_contains($query->sql, '"order_items"."flash_sale_product_id" = ?')) {
                $perProductQueries++;
            }
        });

        FlashSale::factory()->count(3)->create();

        Livewire::test(ListFlashSales::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('units_sold', 7, $sale);

        $this->assertSame(0, $perProductQueries, 'Units sold should be counted for the whole page at once');
    }

    private function copyItem(OrderItem $item, int $flashSaleProductId, int $quantity, bool $cancelled): void
    {
        $subOrder = $item->subOrder()->firstOrFail()->replicate();
        $subOrder->sub_order_number = 'PKG-PERF3-'.$flashSaleProductId.'-'.$quantity;
        $subOrder->fulfillment_status_id = FulfillmentStatus::where('slug', $cancelled ? 'cancelled' : 'processing')->value('id');
        $subOrder->saveQuietly();

        $copy = $item->replicate();
        $copy->sub_order_id = $subOrder->id;
        $copy->flash_sale_product_id = $flashSaleProductId;
        $copy->quantity = $quantity;
        $copy->save();
    }

    public function test_journal_entry_page_loads_line_details_up_front(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $order = Order::firstOrFail();
        $entry = app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'perf3-journal',
            description: 'PERF-3 entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 1000.00, orderId: $order->id),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00, sellerId: $seller->id, orderId: $order->id),
                new JournalLineData(accountSlug: 'commission_revenue', credit: 100.00, sellerId: $seller->id),
            ],
        ));

        Livewire::test(ViewAccountingJournalEntry::class, ['record' => $entry->getKey()])
            ->assertSuccessful()
            ->assertSee($order->order_number);
    }
}
