<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductApprovalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Category $category;

    private Unit $unit;

    private SalesChannel $retail;

    private SalesChannel $wholesale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Approval Store',
            'store_slug' => 'approval-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->category = Category::create(['name' => 'Fabrics', 'slug' => 'fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $this->wholesale = SalesChannel::firstOrCreate(['slug' => SalesChannel::WHOLESALE], ['name' => 'Wholesale', 'is_active' => true]);
    }

    private function product(string $title, string $status = 'approved'): Product
    {
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => true,
        ]);
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 500]);
        $product->ensureDefaultVariation();
        $product->forceFill(['approval_status' => $status, 'approved_at' => $status === 'approved' ? now() : null])->save();

        return $product;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unapprovedStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'pending_review' => ['pending_review'],
            'rejected' => ['rejected'],
        ];
    }

    #[DataProvider('unapprovedStatuses')]
    public function test_unapproved_products_are_hidden_everywhere(string $status): void
    {
        $product = $this->product('Hidden Item', 'approved');
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem($product->id, $product->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);

        $product->forceFill(['approval_status' => $status])->save();

        $this->assertFalse(Product::listable()->whereKey($product->id)->exists());
        $this->get(route('catalog.show', $product->slug))->assertNotFound();
        $this->assertContains('Hidden Item', $cart->unavailableItemTitles());

        $cart->clear();
        $this->expectExceptionMessage('This product is no longer available.');
        $cart->addItem($product->id, $product->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);
    }

    public function test_approved_product_is_visible(): void
    {
        $product = $this->product('Visible Item');

        $this->assertTrue(Product::listable()->whereKey($product->id)->exists());
        $this->get(route('catalog.show', $product->slug))->assertOk();
    }

    public function test_checkout_refuses_a_product_that_is_no_longer_approved(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($customer);
        $product = $this->product('Soon Pulled');
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem($product->id, $product->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);

        $product->forceFill(['approval_status' => 'pending_review'])->save();
        $ordersBefore = Order::count();

        $component = Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Verify Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder');

        $this->assertSame($ordersBefore, Order::count());
        $this->assertStringContainsString('Soon Pulled', (string) $component->get('errorMessage'));
    }

    public function test_approved_price_change_reprices_cart_including_wholesale_tier(): void
    {
        $product = $this->product('Bulk Cloth');
        $product->prices()->create(['sales_channel_id' => $this->wholesale->id, 'min_quantity' => 50, 'max_quantity' => 99, 'unit_price' => 110]);
        $product->prices()->create(['sales_channel_id' => $this->wholesale->id, 'min_quantity' => 100, 'max_quantity' => null, 'unit_price' => 95]);

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem($product->id, $product->variations()->firstOrFail()->id, SalesChannel::WHOLESALE, 120);
        $this->assertSame(95.0, (float) collect($cart->getItems())->first()['unit_price']);

        $product->prices()->where('sales_channel_id', $this->wholesale->id)->delete();
        $product->prices()->create(['sales_channel_id' => $this->wholesale->id, 'min_quantity' => 50, 'max_quantity' => 149, 'unit_price' => 100]);
        $product->prices()->create(['sales_channel_id' => $this->wholesale->id, 'min_quantity' => 150, 'max_quantity' => null, 'unit_price' => 90]);

        $this->assertSame(['Bulk Cloth'], $cart->refreshPrices());
        $this->assertSame('100.00', number_format((float) collect($cart->getItems())->first()['unit_price'], 2, '.', ''));
    }

    public function test_api_catalog_hides_unapproved_products(): void
    {
        $approved = $this->product('Api Approved');
        $pending = $this->product('Api Pending', 'pending_review');

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonFragment(['slug' => $approved->slug])
            ->assertJsonMissing(['slug' => $pending->slug]);

        $this->getJson('/api/v1/catalog/products/'.$approved->slug)->assertOk();
        $this->getJson('/api/v1/catalog/products/'.$pending->slug)->assertNotFound();
    }

    public function test_quick_view_refuses_an_unapproved_product(): void
    {
        $pending = $this->product('Quick Pending', 'pending_review');

        Livewire::test(ProductCatalog::class)
            ->call('openQuickView', $pending->id)
            ->assertSet('quickViewProduct', null)
            ->assertSet('showQuickView', false)
            ->assertSet('errorMessage', 'Product not found.');
    }

    public function test_bundle_with_an_unapproved_secondary_product_adds_nothing(): void
    {
        $main = $this->product('Bundle Main');
        $secondary = $this->product('Bundle Pending', 'pending_review');
        $main->variations()->update(['stock_quantity' => 10, 'is_active' => true]);
        $cart = app(CartSessionService::class);
        $cart->clear();

        Livewire::test(ProductDetail::class, ['slug' => $main->slug])
            ->set('selectedVariationId', $main->variations()->firstOrFail()->id)
            ->call('addBundleToCart', $secondary->id)
            ->assertNotDispatched('cartUpdated');

        $this->assertSame(0, $cart->getItemCount());
    }

    public function test_order_splitter_refuses_a_product_that_is_not_approved(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $pending = $this->product('Split Pending', 'pending_review');
        $ordersBefore = Order::count();

        try {
            app(OrderSplitterService::class)->createAndSplitOrder($customer, [
                'payment_method_id' => PaymentMethod::where('slug', 'cod')->value('id'),
                'shipping_address' => ['recipient_name' => 'X', 'street' => 'Y', 'phone' => '01700000000'],
                'items' => [[
                    'product_id' => $pending->id,
                    'variation_id' => $pending->variations()->firstOrFail()->id,
                    'sales_channel_id' => $this->retail->id,
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ]],
            ]);
            $this->fail('Expected a DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Some items in your cart are no longer available.', $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::count());
    }
}
