<?php

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\CustomerAddresses;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerAddressAndCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_guest_is_redirected_from_address_book(): void
    {
        $this->get(route('customer.addresses'))
            ->assertRedirect();
    }

    public function test_authenticated_user_can_view_saved_addresses(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        Livewire::test(CustomerAddresses::class)
            ->assertStatus(200)
            ->assertSee('My addresses')
            ->assertSee('Home')
            ->assertSee('Uttara Branch Outlet')
            ->assertSee('House 14, Road 5, Dhanmondi');
    }

    public function test_user_can_add_new_delivery_address(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        Livewire::test(CustomerAddresses::class)
            ->call('openCreateModal')
            ->set('address_title', 'Mirpur Showroom')
            ->set('recipient_name', 'Tanvir Hasan')
            ->set('recipient_phone', '01711223344')
            ->set('street_address', 'Section 10, Block C, Mirpur')
            ->set('city', 'Dhaka')
            ->set('postal_code', '1216')
            ->set('is_default', false)
            ->call('saveAddress')
            ->assertHasNoErrors()
            ->assertSee('New address added to your address book.');

        $this->assertDatabaseHas('customer_addresses', [
            'user_id' => $retailUser->id,
            'address_title' => 'Mirpur Showroom',
            'city' => 'Dhaka',
            'recipient_phone' => '01711223344',
        ]);
    }

    public function test_user_can_edit_existing_delivery_address(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $address = $retailUser->addresses()->where('address_title', 'Home')->firstOrFail();

        $this->actingAs($retailUser);

        Livewire::test(CustomerAddresses::class)
            ->call('editAddress', $address->id)
            ->set('street_address', 'House 99, Road 11A, Dhanmondi')
            ->call('saveAddress')
            ->assertHasNoErrors()
            ->assertSee('Address updated successfully.');

        $this->assertSame('House 99, Road 11A, Dhanmondi', $address->fresh()->street_address);
    }

    public function test_user_can_set_new_default_address(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $defaultAddr = $retailUser->addresses()->where('is_default', true)->firstOrFail();
        $secondaryAddr = $retailUser->addresses()->where('is_default', false)->firstOrFail();

        $this->actingAs($retailUser);

        Livewire::test(CustomerAddresses::class)
            ->call('setDefault', $secondaryAddr->id)
            ->assertSee('Default delivery address updated.');

        $this->assertTrue($secondaryAddr->fresh()->is_default);
        $this->assertFalse($defaultAddr->fresh()->is_default);
    }

    public function test_user_can_delete_address(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $address = $retailUser->addresses()->where('address_title', 'Uttara Branch Outlet')->firstOrFail();

        $this->actingAs($retailUser);

        Livewire::test(CustomerAddresses::class)
            ->call('deleteAddress', $address->id)
            ->assertSee('Address removed from your address book.');

        $this->assertDatabaseMissing('customer_addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_checkout_1_click_address_selection_populates_delivery_coordinates(): void
    {
        $wholesaleUser = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->actingAs($wholesaleUser);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::WHOLESALE,
            quantity: 50,
            overrideUnitPrice: 110.0
        );

        $chittagongAddr = $wholesaleUser->addresses()->where('city', 'Chittagong')->firstOrFail();

        Livewire::test(CheckoutComponent::class)
            ->assertStatus(200)
            ->assertSee('Deliver to a saved address')
            ->assertSee('Boutique Salon HQ')
            ->assertSee('Chittagong Distribution Hub')
            ->call('selectSavedAddress', $chittagongAddr->id)
            ->assertSet('city', 'Chattogram')
            ->assertSet('recipientName', 'Karim Ullah')
            ->assertSet('deliveryZone', 'outside_dhaka')
            ->assertSet('postalCode', '4000');
    }

    public function test_checkout_saves_new_address_to_address_book_when_checked(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Hasan')
            ->set('recipientPhone', '01899001122')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->set('saveToAddressBook', true)
            ->set('newAddressTitle', 'Port Warehouse Chittagong')
            ->call('placeOrder')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', [
            'user_id' => $retailUser->id,
            'address_title' => 'Port Warehouse Chittagong',
            'city' => 'Chattogram',
            'postal_code' => '4100',
        ]);
    }

    public function test_checkout_prefills_account_email_for_registered_user(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        Livewire::test(CheckoutComponent::class)
            ->assertSet('recipientEmail', 'customer@retail.com')
            ->assertSee('Email address');
    }

    public function test_checkout_saves_custom_email_in_shipping_address(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Hasan')
            ->set('recipientPhone', '01899001122')
            ->set('recipientEmail', 'alerts@mybusiness.com')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->call('placeOrder')
            ->assertHasNoErrors()
            ->assertRedirect();

        $latestOrder = Order::latest('id')->firstOrFail();
        $this->assertSame('alerts@mybusiness.com', $latestOrder->shipping_address['recipient_email']);
    }

    public function test_checkout_validates_email_format(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Hasan')
            ->set('recipientPhone', '01899001122')
            ->set('recipientEmail', 'invalid-email-address')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->call('placeOrder')
            ->assertHasErrors(['recipientEmail']);
    }
}
