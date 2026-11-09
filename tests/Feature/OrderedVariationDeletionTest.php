<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\VariationInUseException;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * INV-4: an option (variation) that has been ordered can't be deleted, so past orders keep showing what was bought.
 */
class OrderedVariationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariation $ordered;

    private ProductVariation $neverOrdered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variations = $this->product->variations()->orderBy('id')->get();
        $this->ordered = $variations[0];
        $this->neverOrdered = $variations[1];

        OrderItem::where('variation_id', $this->neverOrdered->id)->update(['variation_id' => $this->ordered->id]);
        if (! OrderItem::where('variation_id', $this->ordered->id)->exists()) {
            OrderItem::query()->firstOrFail()->update(['product_id' => $this->product->id, 'variation_id' => $this->ordered->id]);
        }
    }

    public function test_an_ordered_option_cannot_be_deleted(): void
    {
        try {
            $this->ordered->delete();
            $this->fail('Deleting an ordered option must be refused.');
        } catch (VariationInUseException $e) {
            $this->assertStringContainsString('Sellable', $e->getMessage());
        }

        $this->assertTrue(ProductVariation::whereKey($this->ordered->id)->exists());
        $this->assertTrue(OrderItem::where('variation_id', $this->ordered->id)->exists());
    }

    public function test_an_option_never_ordered_can_still_be_deleted(): void
    {
        $this->neverOrdered->delete();

        $this->assertFalse(ProductVariation::whereKey($this->neverOrdered->id)->exists());
    }

    public function test_the_database_refuses_to_delete_an_ordered_option(): void
    {
        $this->expectException(QueryException::class);

        DB::table('product_variations')->where('id', $this->ordered->id)->delete();
    }

    public function test_the_product_form_keeps_an_ordered_option_and_says_why(): void
    {
        $this->actingAs(User::where('email', 'seller@nailsfactory.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('delete')->schemaComponent('variations')->arguments(['item' => "record-{$this->ordered->id}"]))
            ->assertNotified('This option has orders, so it can’t be deleted')
            ->assertSet("data.variations.record-{$this->ordered->id}.sku", $this->ordered->sku)
            ->callAction(TestAction::make('delete')->schemaComponent('variations')->arguments(['item' => "record-{$this->neverOrdered->id}"]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(ProductVariation::whereKey($this->ordered->id)->exists());
        $this->assertFalse(ProductVariation::whereKey($this->neverOrdered->id)->exists());
    }
}
