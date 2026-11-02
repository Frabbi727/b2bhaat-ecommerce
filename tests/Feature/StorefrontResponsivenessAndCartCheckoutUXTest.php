<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontResponsivenessAndCartCheckoutUXTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_storefront_renders_mobile_navigation_bar_and_quick_links(): void
    {
        $response = $this->get(route('catalog.index'));

        $response->assertStatus(200)
            ->assertSee('Mobile App Bottom Navigation Bar')
            ->assertSee('Home')
            ->assertSee('Deals')
            ->assertSee('Cart')
            ->assertSee('Track')
            ->assertSee('Filters');
    }

    public function test_cart_component_allows_item_increment_decrement_and_removal(): void
    {
        $product = Product::with('variations')->firstOrFail();
        $variation = $product->variations->first();

        $cartService = app(CartSessionService::class);
        $cartService->clear();
        $cartService->addItem(
            productId: $product->id,
            variationId: $variation?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        $cartKey = array_key_first($cartService->getItems());

        Livewire::test(CartComponent::class)
            ->assertSee($product->title)
            ->call('updateQuantity', $cartKey, 3);

        $this->assertEquals(3, $cartService->getItemCount());

        Livewire::test(CartComponent::class)
            ->call('removeItem', $cartKey);

        $this->assertEquals(0, $cartService->getItemCount());
    }

    public function test_checkout_component_validates_bangladeshi_phone_and_address_requirements(): void
    {
        $product = Product::with('variations')->firstOrFail();
        $cartService = app(CartSessionService::class);
        $cartService->clear();
        $cartService->addItem(
            productId: $product->id,
            variationId: $product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        $cod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        // Invalid phone number
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Ahmed')
            ->set('recipientPhone', '99999') // Invalid
            ->set('streetAddress', 'House 5, Road 2')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1212')
            ->set('selectedPaymentMethodId', $cod->id)
            ->call('placeOrder')
            ->assertHasErrors(['recipientPhone']);

        // Valid phone number
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Ahmed')
            ->set('recipientPhone', '01711223344') // Valid BD
            ->set('streetAddress', 'House 5, Road 2, Gulshan 1')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1212')
            ->set('selectedPaymentMethodId', $cod->id)
            ->call('placeOrder')
            ->assertHasNoErrors(['recipientPhone', 'streetAddress', 'recipientName']);
    }

    public function test_checkout_component_warns_on_cod_order_limit_excess(): void
    {
        $product = Product::with('variations')->firstOrFail();
        $cartService = app(CartSessionService::class);
        $cartService->clear();

        // Add item with unit price 60,000 Tk to exceed COD limit (50,000 Tk)
        $cartService->addItem(
            productId: $product->id,
            variationId: $product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1,
            overrideUnitPrice: 60000.00
        );

        $cod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Md. Rafiqul Islam')
            ->set('recipientPhone', '01811223344')
            ->set('streetAddress', 'Tejgaon Light Industrial Area')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1208')
            ->set('selectedPaymentMethodId', $cod->id)
            ->call('placeOrder')
            ->assertSet('errorMessage', 'Cash on Delivery is only available for orders up to ৳50,000');
    }

    public function test_checkout_component_requires_mfs_trxid_for_bkash_and_nagad(): void
    {
        $product = Product::with('variations')->firstOrFail();
        $cartService = app(CartSessionService::class);
        $cartService->clear();
        $cartService->addItem(
            productId: $product->id,
            variationId: $product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        $bkash = PaymentMethod::where('slug', 'bkash')->firstOrFail();

        // Attempting to checkout with bKash without TrxID fails validation
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Sadia Jahan')
            ->set('recipientPhone', '01911223344')
            ->set('streetAddress', 'Apartment 4B, Uttara Sector 3')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('selectedPaymentMethodId', $bkash->id)
            ->set('mfsSenderNumber', '')
            ->set('mfsTransactionId', '')
            ->call('placeOrder')
            ->assertHasErrors(['mfsSenderNumber', 'mfsTransactionId']);

        // With valid MFS details, order proceeds successfully
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->actingAs($customer);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Sadia Jahan')
            ->set('recipientPhone', '01911223344')
            ->set('streetAddress', 'Apartment 4B, Uttara Sector 3')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('selectedPaymentMethodId', $bkash->id)
            ->set('mfsSenderNumber', '01911223344')
            ->set('mfsTransactionId', 'BK99XTR882')
            ->call('placeOrder')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'payment_method_id' => $bkash->id,
        ]);
    }
}
