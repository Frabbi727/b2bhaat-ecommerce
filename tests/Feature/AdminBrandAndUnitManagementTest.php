<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Brands\Pages\CreateBrand;
use App\Filament\Admin\Resources\Brands\Pages\ListBrands;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductBulkImportService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBrandAndUnitManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_adds_a_brand_and_a_unit_that_sellers_can_then_import_with(): void
    {
        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Nails Factory Ltd', 'slug' => 'nails-factory-ltd', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateUnit::class)
            ->fillForm(['name' => 'Tray', 'symbol' => ' TRAY '])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('tray', Unit::where('name', 'Tray')->value('symbol'));

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $category = Product::where('seller_id', $seller->id)->firstOrFail()->category->name;
        $csv = "product_title,category,sell_to,unit,stock,retail_price,brand\nNail Tray Organiser,{$category},retail,tray,10,250,Nails Factory Ltd\n";

        $result = app(ProductBulkImportService::class)->import($seller, $csv);

        $this->assertSame([], $result['errors']);
        $product = Product::where('title', 'Nail Tray Organiser')->firstOrFail();
        $this->assertSame('Nails Factory Ltd', $product->brand->name);
        $this->assertSame('tray', $product->unit->symbol);
    }

    public function test_duplicate_names_and_codes_are_rejected(): void
    {
        Livewire::test(CreateUnit::class)
            ->fillForm(['name' => 'Piece', 'symbol' => 'pc'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique', 'symbol' => 'unique']);
    }

    public function test_brands_and_units_in_use_cannot_be_deleted(): void
    {
        $usedUnit = Product::firstOrFail()->unit;
        $unusedUnit = Unit::create(['name' => 'Spare', 'symbol' => 'spare']);

        Livewire::test(ListUnits::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($usedUnit))
            ->callAction(TestAction::make(DeleteAction::class)->table($unusedUnit));

        $this->assertModelExists($usedUnit);
        $this->assertModelMissing($unusedUnit);

        $usedBrand = Brand::whereHas('products')->firstOrFail();
        Livewire::test(ListBrands::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($usedBrand));
    }

    public function test_sellers_cannot_open_the_admin_screens(): void
    {
        $this->actingAs(User::where('email', 'seller@nailsfactory.com')->firstOrFail());

        $this->get('/admin/units')->assertForbidden();
        $this->get('/admin/brands')->assertForbidden();
    }
}
