<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductModeration\Pages\ListModeratedProducts;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * INV-9: only the platform decides which products are featured on the homepage; sellers can't feature their own.
 */
class ProductFeaturingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->product->forceFill(['is_featured' => false])->save();
    }

    public function test_a_seller_cannot_feature_their_own_product(): void
    {
        $this->actingAs(User::findOrFail($this->product->seller_id));
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertFormFieldDoesNotExist('is_featured')
            ->set('data.is_featured', true)
            ->call('save');

        $this->assertFalse($this->product->fresh()->is_featured);

        $this->product->update(['is_featured' => true]);
        $this->assertFalse($this->product->fresh()->is_featured, 'is_featured must not be mass-assignable');
    }

    public function test_an_admin_features_and_unfeatures_a_product(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListModeratedProducts::class)
            ->callTableAction('feature', $this->product)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($this->product->fresh()->is_featured);

        Livewire::test(ListModeratedProducts::class)
            ->callTableAction('unfeature', $this->product->fresh())
            ->assertHasNoTableActionErrors();
        $this->assertFalse($this->product->fresh()->is_featured);
    }
}
