<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\CustomerAuth;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A shopper without an account confirms their phone with an SMS code before an order is placed.
 */
class GuestCheckoutIdentityTest extends TestCase
{
    use RefreshDatabase;

    private int $existingOrderId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->existingOrderId = (int) Order::max('id');
    }

    public function test_guest_order_waits_for_the_sms_code_and_nothing_is_placed_yet(): void
    {
        $this->fillCheckout('01899001199')
            ->call('placeOrder')
            ->assertSet('errorMessage', '')
            ->assertSet('awaitingPhoneCode', true);

        $this->assertCount(0, $this->newOrders());
        $this->assertFalse(User::wherePhone('8801899001199')->exists());
        $this->assertSame('8801899001199', collect($this->sentSms())->last()['to'] ?? null);
    }

    public function test_right_code_makes_an_account_signs_in_and_places_the_order(): void
    {
        $this->placeOrderConfirmingPhone($this->fillCheckout('01899001199'))
            ->assertSet('errorMessage', '')
            ->assertRedirect();

        $order = $this->newOrders()->sole();
        $customer = $order->customer;
        $this->assertSame('8801899001199', $customer->phone);
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertStringEndsWith('@guest.b2bhaat.invalid', $customer->email);
        $this->assertFalse(Hash::check('password', $customer->password));
        $this->assertSame($customer->id, Auth::id());
    }

    public function test_wrong_code_places_no_order(): void
    {
        $this->fillCheckout('01899001199')
            ->call('placeOrder')
            ->set('phoneCode', '000000')
            ->call('confirmPhoneCode')
            ->assertHasErrors(['phoneCode'])
            ->assertSet('awaitingPhoneCode', true);

        $this->assertCount(0, $this->newOrders());
        $this->assertGuest();
    }

    public function test_changing_the_phone_after_the_code_was_sent_places_no_order(): void
    {
        $checkout = $this->fillCheckout('01899001199')->call('placeOrder');
        $code = $this->lastSentSmsCode();

        $checkout->set('recipientPhone', '01899001188')
            ->set('phoneCode', $code)
            ->call('confirmPhoneCode')
            ->assertSet('awaitingPhoneCode', false)
            ->assertSet('errorMessage', 'The phone number changed. Please press Place order again to get a code for the new number.');

        $this->assertCount(0, $this->newOrders());
        $this->assertGuest();
    }

    public function test_confirmed_phone_of_a_registered_shopper_orders_into_their_own_account(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $customer->update(['phone' => '8801899001177']);

        $this->placeOrderConfirmingPhone($this->fillCheckout('01899001177'))->assertSet('errorMessage', '');

        $this->assertSame($customer->id, $this->newOrders()->sole()->customer_id);
        $this->assertSame($customer->id, Auth::id());
    }

    public function test_seller_phone_is_refused_before_any_sms_is_sent(): void
    {
        $seller = User::whereHas('role', fn ($query) => $query->where('slug', 'seller'))->firstOrFail();
        $smsBefore = count($this->sentSms());

        $this->fillCheckout((string) $seller->phone)
            ->call('placeOrder')
            ->assertSet('awaitingPhoneCode', false)
            ->assertSet('errorMessage', 'This phone belongs to a seller or staff account. Please sign in with your password to order.');

        $this->assertCount($smsBefore, $this->sentSms());
        $this->assertCount(0, $this->newOrders());
    }

    public function test_when_guest_checkout_is_off_no_sms_is_sent(): void
    {
        app(StoreConfigurationService::class)->set('guest_checkout_enabled', false, 'checkout', 'boolean');

        $this->fillCheckout('01899001199')
            ->call('placeOrder')
            ->assertSet('awaitingPhoneCode', false)
            ->assertSet('errorMessage', 'Please sign in or create an account to place your order.');

        $this->assertSame([], $this->sentSms());
    }

    public function test_two_new_shoppers_get_separate_accounts(): void
    {
        $this->placeOrderConfirmingPhone($this->fillCheckout('01899001199', 'First Shopper'));
        Auth::logout();
        $this->placeOrderConfirmingPhone($this->fillCheckout('01899001188', 'Second Shopper'));

        $this->assertCount(2, $this->newOrders());
        $this->assertCount(2, $this->newOrders()->pluck('customer_id')->unique());
    }

    public function test_shopper_who_registers_later_keeps_the_checkout_account_and_orders(): void
    {
        $this->placeOrderConfirmingPhone($this->fillCheckout('01899001199'));
        $account = $this->newOrders()->sole()->customer;
        Auth::logout();

        $this->registerWithPhone('01899001199')
            ->assertSet('errorMessage', '')
            ->assertRedirect(route('customer.orders'));

        $account->refresh();
        $this->assertSame($account->id, Auth::id());
        $this->assertSame('rahim@b2bhaat-test.com', $account->email);
        $this->assertSame('Rahim Chowdhury', $account->name);
        $this->assertTrue(Hash::check('SecretPass123!', $account->password));
        $this->assertSame(1, User::wherePhone('8801899001199')->count());
        $this->assertTrue(Order::where('customer_id', $account->id)->exists());
    }

    public function test_registering_with_a_wholesale_buyer_phone_is_refused(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $customer->forceFill([
            'phone' => '8801899001199',
            'role_id' => Role::where('slug', 'wholesale_buyer')->value('id'),
            'is_wholesale_approved' => true,
        ])->save();

        $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']))
            ->call('register')
            ->assertHasErrors(['registerPhone']);
    }

    private function fillCheckout(string $phone, string $name = 'Guest Buyer'): Testable
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', $name)
            ->set('recipientPhone', $phone)
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'));
    }

    private function registerWithPhone(string $phone): Testable
    {
        $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']), $phone)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('step', 'otp_verification');

        return $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']), $phone)
            ->set('step', 'otp_verification')
            ->set('otpCode', $this->lastSentSmsCode())
            ->call('completeRegistration');
    }

    private function fillRegistration(Testable $component, string $phone = '01899001199'): Testable
    {
        return $component
            ->set('registerName', 'Rahim Chowdhury')
            ->set('registerPhone', $phone)
            ->set('registerEmail', 'rahim@b2bhaat-test.com')
            ->set('registerPassword', 'SecretPass123!')
            ->set('registerPasswordConfirmation', 'SecretPass123!')
            ->set('agreeTerms', true);
    }

    /**
     * @return Collection<int, Order>
     */
    private function newOrders(): Collection
    {
        return Order::where('id', '>', $this->existingOrderId)->get();
    }

    /**
     * @return array<int, array{to: string, message: string, purpose: ?string}>
     */
    private function sentSms(): array
    {
        return app(SmsGatewayInterface::class)->sentMessages;
    }
}
