<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Round 2: plain wording, a checkout people in Bangladesh can complete, and payment instructions that are real.
 */
class PlainLanguageCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        app(CartSessionService::class)->addItem($this->nails->id, $this->nails->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);
    }

    private function fillAddress(Testable $checkout): Testable
    {
        return $checkout
            ->set('recipientName', 'Nusrat Jahan')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'House 12, Road 5, Dhanmondi');
    }

    // Payment instructions must never point at a made-up number
    public function test_bkash_and_nagad_are_hidden_until_the_admin_enters_the_receiving_number(): void
    {
        Livewire::test(CheckoutComponent::class)
            ->assertDontSee('01700-000004')
            ->assertDontSee('bKash')
            ->assertDontSee('Nagad')
            ->assertSee('Cash on Delivery');

        $this->assertFalse(PaymentMethod::availableToBuyers()->where('slug', 'bkash')->exists());
    }

    public function test_bkash_instructions_show_the_configured_number_and_honest_timing(): void
    {
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');

        Livewire::test(CheckoutComponent::class)
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'bkash')->value('id'))
            ->assertSee('01811-223344')
            ->assertDontSee('instant TrxID confirmation')
            ->assertSee('We check your payment before the seller sends your order');
    }

    public function test_admin_can_set_the_bkash_and_nagad_numbers(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'store_name' => 'B2B Haat',
                'vat_bin_number' => '000111222-0101',
                'helpline_phone' => '+880 1711-000000',
                'support_email' => 'help@shop.example',
                'store_address' => 'House 1, Road 1, Dhaka',
                'bkash_merchant_number' => '01811-223344',
                'nagad_merchant_number' => '01911-223344',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = app(StoreConfigurationService::class);
        $this->assertSame('01811-223344', $config->get('bkash_merchant_number'));
        $this->assertSame('01911-223344', $config->get('nagad_merchant_number'));
    }

    // Wording
    public function test_checkout_and_cart_speak_plainly(): void
    {
        Livewire::test(CheckoutComponent::class)
            ->assertSee('Place order')
            ->assertSee('Postal code (optional)')
            ->assertDontSee('Logistics Manifest')
            ->assertDontSee('partition')
            ->assertDontSee('Lock Stock')
            ->assertDontSee('Freight')
            ->assertDontSee('Consignment');

        Livewire::test(CartComponent::class)
            ->assertSee('Go to checkout')
            ->assertDontSee('SELECT FOR UPDATE')
            ->assertDontSee('Sourcing')
            ->assertDontSee('B2C');
    }

    // Simpler checkout
    public function test_order_can_be_placed_without_a_postal_code_and_with_a_district_from_the_list(): void
    {
        $checkout = $this->fillAddress(Livewire::test(CheckoutComponent::class))
            ->set('city', 'Rajshahi')
            ->set('postalCode', '')
            ->assertSet('deliveryZone', 'outside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('Rajshahi', $order->shipping_address['city']);
        $checkout->assertRedirect();
    }

    public function test_old_district_spellings_are_accepted_and_saved_with_the_official_name(): void
    {
        $this->fillAddress(Livewire::test(CheckoutComponent::class))
            ->set('city', 'Chittagong')
            ->set('deliveryZone', 'outside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder')
            ->assertHasNoErrors();

        $this->assertSame('Chattogram', Order::latest('id')->firstOrFail()->shipping_address['city']);
    }

    public function test_unknown_district_is_refused(): void
    {
        $ordersBefore = Order::count();

        $this->fillAddress(Livewire::test(CheckoutComponent::class))
            ->set('city', 'Atlantis')
            ->call('placeOrder')
            ->assertHasErrors(['city']);

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_a_district_outside_dhaka_cannot_keep_the_cheaper_dhaka_rate(): void
    {
        $this->fillAddress(Livewire::test(CheckoutComponent::class))
            ->set('deliveryZone', 'inside_dhaka')
            ->set('city', 'Sylhet')
            ->assertSet('deliveryZone', 'outside_dhaka');
    }

    public function test_choosing_a_district_picks_the_matching_delivery_area(): void
    {
        Livewire::test(CheckoutComponent::class)
            ->set('city', 'Gazipur')->assertSet('deliveryZone', 'sub_dhaka')
            ->set('city', 'Dhaka')->assertSet('deliveryZone', 'inside_dhaka')
            ->set('city', 'Khulna')->assertSet('deliveryZone', 'outside_dhaka');
    }

    // Wholesale applicants know where they stand
    public function test_wholesale_applicant_sees_that_the_application_is_under_review(): void
    {
        $applicant = User::where('email', 'customer@retail.com')->firstOrFail();
        $applicant->update(['trade_license_number' => 'TRAD/DNCC/2026/1', 'business_name' => 'Nusrat Boutique', 'is_wholesale_approved' => false]);
        $this->actingAs($applicant);

        $this->get(route('catalog.index'))->assertSee('Your wholesale application is under review')->assertDontSee('Consumer Market Mode');
        $this->get(route('catalog.show', $this->nails->slug))->assertSee('Your wholesale application is under review');
    }

    public function test_shopper_is_invited_to_apply_for_wholesale_in_plain_words(): void
    {
        $this->get(route('catalog.index'))
            ->assertSee('Buying for a business?')
            ->assertDontSee('islandwide')
            ->assertDontSee('Upgrade to B2B Wholesale');
    }

    // Guests and sign-in
    public function test_guest_can_order_and_is_offered_sign_in_without_being_forced(): void
    {
        Livewire::test(CheckoutComponent::class)
            ->assertSee('Have an account?')
            ->assertSee(route('login', ['redirect' => '/checkout']), false);
    }

    public function test_when_guest_checkout_is_off_the_guest_is_told_before_filling_the_form(): void
    {
        app(StoreConfigurationService::class)->set('guest_checkout_enabled', false, 'checkout', 'boolean');

        Livewire::test(CheckoutComponent::class)
            ->assertSee('Please sign in or create an account to place your order.')
            ->assertDontSee('Place order');
    }

    public function test_signed_in_user_without_a_phone_gets_an_empty_phone_field_not_a_made_up_number(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $user->forceFill(['phone' => null])->save();
        $user->addresses()->delete();
        $this->actingAs($user);

        Livewire::test(CheckoutComponent::class)
            ->assertSet('recipientPhone', '')
            ->assertDontSee('01700000004');
    }
}
