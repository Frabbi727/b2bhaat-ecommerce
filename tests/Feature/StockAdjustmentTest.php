<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * INV-3: saving the product form never overwrites live stock; stock changes through "Adjust stock".
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'seller@nailsfactory.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->product->variations()->update(['stock_quantity' => 20]);
    }

    private function firstVariation(): ProductVariation
    {
        return $this->product->variations()->orderBy('id')->firstOrFail();
    }

    private function simpleProduct(int $stock): Product
    {
        $this->product->variations()->where('id', '!=', $this->firstVariation()->id)->delete();
        $this->product->update(['has_variations' => false]);
        $this->firstVariation()->update(['stock_quantity' => $stock]);

        return $this->product->fresh();
    }

    public function test_saving_the_form_keeps_units_sold_while_it_was_open(): void
    {
        $page = Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()]);

        // An order reserves 3 units while the seller is editing.
        $this->firstVariation()->decrement('stock_quantity', 3);

        $page->fillForm(['short_description' => 'Updated summary'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(17, $this->firstVariation()->fresh()->stock_quantity);
    }

    public function test_stock_fields_are_read_only_on_edit(): void
    {
        $product = $this->simpleProduct(9);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldIsDisabled('simple_stock_quantity')
            ->fillForm(['simple_stock_quantity' => 500])
            ->call('save');

        $this->assertSame(9, $this->firstVariation()->fresh()->stock_quantity);
    }

    public function test_adjust_stock_adds_to_the_live_figure_and_is_audited(): void
    {
        $product = $this->simpleProduct(10);
        $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

        $this->firstVariation()->decrement('stock_quantity', 4);

        $page->callAction('adjustStock', ['change' => 25, 'reason' => 'received', 'note' => 'Factory delivery'])
            ->assertHasNoActionErrors()
            ->assertSet('data.simple_stock_quantity', 31);

        $this->assertSame(31, $this->firstVariation()->fresh()->stock_quantity);

        $entry = AuditLog::where('action', 'stock_adjusted')->sole();
        $this->assertSame(['stock_quantity' => 6], $entry->old_values);
        $this->assertSame(['stock_quantity' => 31], $entry->new_values);
        $this->assertSame('received', $entry->metadata['reason']);
        $this->assertSame(25, $entry->metadata['change']);
    }

    public function test_removing_more_than_is_in_stock_is_refused(): void
    {
        $product = $this->simpleProduct(2);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('adjustStock', ['change' => -5, 'reason' => 'damaged'])
            ->assertNotified('Stock not changed');

        $this->assertSame(2, $this->firstVariation()->fresh()->stock_quantity);
        $this->assertSame(0, AuditLog::where('action', 'stock_adjusted')->count());
    }

    public function test_seller_adjusts_one_option_from_the_product_list(): void
    {
        $second = $this->product->variations()->orderBy('id')->skip(1)->firstOrFail();

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($this->product), [
                'variation_id' => $second->id,
                'change' => -5,
                'reason' => 'sold_elsewhere',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(15, $second->fresh()->stock_quantity);
        $this->assertSame(20, $this->firstVariation()->fresh()->stock_quantity);
    }

    public function test_an_option_of_another_product_cannot_be_adjusted(): void
    {
        $other = $this->product->replicate(['slug', 'sku']);
        $other->slug = 'another-sellers-item';
        $other->sku = 'OTHER-1';
        $other->save();
        $foreign = $other->variations()->create(['sku' => 'OTHER-01', 'stock_quantity' => 7, 'buy_price' => 0, 'is_active' => true]);
        $before = 7;

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('adjustStock')->table($this->product), [
                'variation_id' => $foreign->id,
                'change' => 50,
                'reason' => 'received',
            ]);

        $this->assertSame($before, $foreign->fresh()->stock_quantity);
    }
}
