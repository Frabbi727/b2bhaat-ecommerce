<?php

namespace Tests\Feature;

use App\Filament\Seller\Pages\StoreSettings;
use App\Livewire\Storefront\ProductDetail;
use App\Livewire\Storefront\SellerStore;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MerchantVacationModeAndStockAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_update_vacation_mode_settings_in_filament(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $profile = $seller->sellerProfile;

        $this->actingAs($seller);

        Livewire::test(StoreSettings::class)
            ->assertStatus(200)
            ->assertSet('is_on_vacation', false)
            ->set('is_on_vacation', true)
            ->set('vacation_message', 'Factory closed for annual maintenance. Resuming dispatches on Nov 10.')
            ->set('vacation_ends_at', '2026-11-10')
            ->call('save')
            ->assertHasNoErrors();

        $profile->refresh();
        $this->assertTrue($profile->is_on_vacation);
        $this->assertSame('Factory closed for annual maintenance. Resuming dispatches on Nov 10.', $profile->vacation_message);
        $this->assertSame('2026-11-10', $profile->vacation_ends_at->format('Y-m-d'));
    }

    public function test_storefront_displays_vacation_banner_and_disables_retail_add_to_cart(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $sellerProfile = $product->seller->sellerProfile;

        // Turn on vacation mode
        $sellerProfile->update([
            'is_on_vacation' => true,
            'vacation_message' => 'Factory closed for Eid holidays. Orders temporarily paused.',
            'vacation_ends_at' => now()->addDays(7)->toDateString(),
        ]);

        $retailBuyer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailBuyer);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Currently on Vacation Mode')
            ->assertSee('Factory closed for Eid holidays. Orders temporarily paused.')
            ->assertSee('Store on Vacation (Ordering Disabled)')
            ->call('addToCartRetail')
            ->assertSet('errorMessage', 'This merchant is currently on vacation mode. Ordering is temporarily disabled.');
    }

    public function test_wholesale_bulk_order_is_blocked_when_seller_is_on_vacation(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $sellerProfile = $product->seller->sellerProfile;

        $sellerProfile->update([
            'is_on_vacation' => true,
            'vacation_message' => 'Factory closed for Eid holidays.',
        ]);

        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->actingAs($wholesaleBuyer);

        $variation = $product->variations()->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Factory dispatches paused in Vacation Mode')
            ->set("matrixQuantities.{$variation->id}", 60)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', 'This merchant is currently on vacation mode. Ordering is temporarily disabled.');
    }

    public function test_cart_service_and_order_splitter_throw_exception_when_seller_is_on_vacation(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $sellerProfile = $product->seller->sellerProfile;

        $sellerProfile->update([
            'is_on_vacation' => true,
            'vacation_message' => 'Paused for renovations.',
        ]);

        $cart = app(CartSessionService::class);
        $cart->clear();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is currently on vacation mode');

        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );
    }

    public function test_order_splitter_prevents_order_placement_when_seller_is_on_vacation(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $retailBuyer = User::where('email', 'customer@retail.com')->firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        $product->seller->sellerProfile->update([
            'is_on_vacation' => true,
            'vacation_message' => 'Store temporarily closed.',
        ]);

        $splitter = app(OrderSplitterService::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot place order: NailsFactory Ltd. is currently on vacation mode.');

        $splitter->createAndSplitOrder($retailBuyer, [
            'payment_method_id' => 1,
            'shipping_address' => [
                'recipient_name' => 'Fatima Retail',
                'recipient_phone' => '01700000004',
                'street_address' => 'Mirpur-10, Dhaka',
                'city' => 'Dhaka',
                'postal_code' => '1216',
                'delivery_zone' => 'inside_dhaka',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 1,
                    'unit_price' => 180.0,
                ],
            ],
        ]);
    }

    public function test_seller_storefront_page_shows_vacation_notice(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $profile = $seller->sellerProfile;

        $profile->update([
            'is_on_vacation' => true,
            'vacation_message' => 'Our factory is undergoing scheduled maintenance until Nov 15.',
            'vacation_ends_at' => '2026-11-15',
        ]);

        Livewire::test(SellerStore::class, ['slug' => $profile->store_slug])
            ->assertStatus(200)
            ->assertSee('Factory Currently on Vacation Mode')
            ->assertSee('Our factory is undergoing scheduled maintenance until Nov 15.')
            ->assertSee('Nov 15, 2026');
    }

    public function test_low_stock_variations_detected_and_listed_on_store_settings_page(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = $seller->products()->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        // Artificially drop variation stock to 4 units (<= 10 is low stock)
        $variation->update(['stock_quantity' => 4]);

        $this->actingAs($seller);

        Livewire::test(StoreSettings::class)
            ->assertStatus(200)
            ->assertSee('Low Stock Warnings (Stock ≤ 10 units)')
            ->assertSee($variation->sku)
            ->assertSee('4 units left')
            ->assertSee('Update Stock');
    }
}
