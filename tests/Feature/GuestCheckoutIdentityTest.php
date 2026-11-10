<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\CustomerAuth;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class GuestCheckoutIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->existingOrderId = (int) Order::max('id');
    }

    private int $existingOrderId = 0;

    private function newOrders(): Collection
    {
        return Order::where('id', '>', $this->existingOrderId)->get();
    }

    private function placeGuestOrder(string $phone, string $name = 'Guest Buyer'): Testable
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
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder');
    }

    public function test_guest_checkout_does_not_create_login_with_known_password(): void
    {
        $this->placeGuestOrder('01899001199');

        $guest = Order::latest('id')->firstOrFail()->customer;
        $this->assertSame('8801899001199', $guest->phone);
        $this->assertFalse(Hash::check('password', $guest->password), 'Guest account has the known password "password"');
        $this->assertStringEndsWith('@guest.b2bhaat.invalid', $guest->email);
    }

    public function test_two_guests_in_same_second_get_distinct_accounts(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->placeGuestOrder('01899001199', 'First Guest');
        $this->placeGuestOrder('01899001188', 'Second Guest');

        $this->assertCount(2, $this->newOrders());
        $this->assertCount(2, $this->newOrders()->pluck('customer_id')->unique());
    }

    public function test_returning_guest_phone_reuses_existing_user_without_error(): void
    {
        $this->placeGuestOrder('01899001199');
        $this->placeGuestOrder('+8801899001199')->assertSet('errorMessage', '');

        $this->assertCount(2, $this->newOrders());
        $this->assertCount(1, $this->newOrders()->pluck('customer_id')->unique());
    }

    public function test_guest_phone_matching_seller_account_is_refused(): void
    {
        $seller = User::whereHas('role', fn ($q) => $q->where('slug', 'seller'))->firstOrFail();

        $this->placeGuestOrder((string) $seller->phone)
            ->assertSet('errorMessage', 'This phone belongs to a registered account. Please sign in to order.');

        $this->assertFalse(Order::where('customer_id', $seller->id)->exists());
    }

    public function test_guest_phone_matching_registered_retail_account_is_refused(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $customer->update(['phone' => '8801899001177']);

        $this->placeGuestOrder('01899001177')
            ->assertSet('errorMessage', 'This phone belongs to a registered account. Please sign in to order.');

        $this->assertFalse(Order::where('customer_id', $customer->id)->where('id', '>', $this->existingOrderId)->exists());
    }

    public function test_guest_who_registers_later_takes_over_the_guest_account_and_keeps_orders(): void
    {
        $this->placeGuestOrder('01899001199');
        $guest = Order::latest('id')->firstOrFail()->customer;

        $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']))
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('step', 'otp_verification');

        $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']))
            ->set('step', 'otp_verification')
            ->set('otpCode', $this->lastSentOtpCode())
            ->call('completeRegistration')
            ->assertSet('errorMessage', '')
            ->assertRedirect(route('customer.orders'));

        $guest->refresh();
        $this->assertSame($guest->id, auth()->id());
        $this->assertSame('rahim@b2bhaat-test.com', $guest->email);
        $this->assertSame('Rahim Chowdhury', $guest->name);
        $this->assertTrue(Hash::check('SecretPass123!', $guest->password));
        $this->assertNotNull($guest->phone_verified_at);
        $this->assertSame(1, User::wherePhone('8801899001199')->count());
        $this->assertTrue(Order::where('customer_id', $guest->id)->exists());
    }

    public function test_registering_with_phone_of_a_registered_account_is_still_refused(): void
    {
        User::where('email', 'customer@retail.com')->firstOrFail()->update(['phone' => '8801899001199']);

        $this->fillRegistration(Livewire::test(CustomerAuth::class, ['tab' => 'register']))
            ->call('register')
            ->assertHasErrors(['registerPhone']);
    }

    public function test_guest_account_signed_in_by_otp_is_no_longer_open_to_guest_orders(): void
    {
        $this->placeGuestOrder('01899001199');

        Livewire::test(CustomerAuth::class)
            ->set('otpPhone', '01899001199')
            ->call('sendLoginOtp');

        Livewire::test(CustomerAuth::class)
            ->set('otpPhone', '01899001199')
            ->set('otpCode', $this->lastSentOtpCode())
            ->call('verifyLoginOtp')
            ->assertRedirect(route('customer.orders'));

        $this->assertNotNull(User::wherePhone('8801899001199')->value('phone_verified_at'));

        auth()->logout();
        $this->placeGuestOrder('01899001199')
            ->assertSet('errorMessage', 'This phone belongs to a registered account. Please sign in to order.');
    }

    private function fillRegistration(Testable $component): Testable
    {
        return $component
            ->set('registerName', 'Rahim Chowdhury')
            ->set('registerPhone', '01899001199')
            ->set('registerEmail', 'rahim@b2bhaat-test.com')
            ->set('registerPassword', 'SecretPass123!')
            ->set('registerPasswordConfirmation', 'SecretPass123!')
            ->set('agreeTerms', true);
    }

    private function lastSentOtpCode(): string
    {
        $sent = app(SmsGatewayInterface::class)->sentMessages;
        preg_match('/\b(\d{6})\b/', (string) end($sent)['message'], $matches);

        return $matches[1] ?? '';
    }
}
