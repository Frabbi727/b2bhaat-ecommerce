<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlashSaleStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->nails = Product::where('seller_id', $this->seller->id)->where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
    }

    public function test_sync_marks_started_and_finished_campaigns(): void
    {
        $started = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);

        $finished = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Active,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('flash-sales:sync-statuses')
            ->expectsOutput('1 started, 1 ended.')
            ->assertExitCode(0);

        $this->assertSame(FlashSaleStatus::Active, $started->fresh()->status);
        $this->assertSame(FlashSaleStatus::Ended, $finished->fresh()->status);
        $this->assertNotNull($finished->fresh()->ended_at);
    }

    public function test_late_sync_never_leaves_a_price_live(): void
    {
        $sale = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Active,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinute(), // Expired, but sync has not run yet!
        ]);

        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->nails->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 50,
        ]);

        $this->assertFalse($sale->isLive());

        $cart = app(CartSessionService::class);
        $variation = $this->nails->variations()->firstOrFail();
        $cart->addItem($this->nails->id, $variation->id, SalesChannel::RETAIL, 1);

        $line = $cart->getItems()["{$this->nails->id}_{$variation->id}_retail"];
        $this->assertNull($line['flash_sale_product_id']);
    }

    public function test_seller_product_form_has_no_flash_price_fields(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->assertFormFieldDoesNotExist('flash_sale_price')
            ->assertFormFieldDoesNotExist('flash_sale_end')
            ->assertFormFieldDoesNotExist('is_flash_sale');
    }
}
