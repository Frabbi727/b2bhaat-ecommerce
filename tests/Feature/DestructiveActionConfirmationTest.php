<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\FlashSale;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX-4: actions that take something off sale or out of sight ask before they run.
 */
class DestructiveActionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_pausing_a_live_flash_sale_asks_first(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $sale = FlashSale::factory()->live()->create();

        $asksFirst = fn (Action $action): bool => $action->isConfirmationRequired();

        Livewire::test(ListFlashSales::class)
            ->assertActionExists(TestAction::make('pause')->table($sale), $asksFirst);

        Livewire::test(EditFlashSale::class, ['record' => $sale->getKey()])
            ->assertActionExists('pause', $asksFirst);
    }

    public function test_hiding_products_in_bulk_asks_first(): void
    {
        $this->actingAs(User::where('email', 'seller@nailsfactory.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListProducts::class)
            ->assertActionExists(TestAction::make('hide')->table()->bulk(), fn (Action $action): bool => $action->isConfirmationRequired());
    }
}
