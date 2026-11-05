<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-058: sellers upload product photos from the panel; the storefront renders stored paths and seeded URLs.
 */
class SellerProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->product = Product::where('seller_id', $seller->id)->where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->product->images()->delete();

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        Storage::fake('public');
    }

    public function test_seller_can_upload_product_image(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm(['images' => [['image_url' => [UploadedFile::fake()->image('front.jpg', 800, 800)], 'is_primary' => true]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $image = ProductImage::where('product_id', $this->product->id)->sole();
        $this->assertTrue($image->is_primary);
        $this->assertStringStartsWith('products/', $image->image_url);
        Storage::disk('public')->assertExists($image->image_url);

        // Storefront renders the stored path through the public disk URL
        $this->assertSame(Storage::disk('public')->url($image->image_url), $image->public_url);
        $this->get(route('catalog.show', $this->product->slug))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($image->image_url), false);
    }

    public function test_non_image_rejected(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm(['images' => [['image_url' => [UploadedFile::fake()->create('shell.php', 10, 'application/x-php')], 'is_primary' => true]]])
            ->call('save')
            ->assertHasFormErrors(['images.0.image_url']);

        $undoRepeaterFake();

        $this->assertSame(0, ProductImage::where('product_id', $this->product->id)->count());
    }

    public function test_seeded_absolute_urls_still_render(): void
    {
        $image = ProductImage::create([
            'product_id' => $this->product->id,
            'image_url' => 'https://images.unsplash.com/photo-test?w=800',
            'is_primary' => true,
        ]);

        $this->assertSame('https://images.unsplash.com/photo-test?w=800', $image->public_url);
    }
}
