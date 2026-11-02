<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CustomerAuth;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerStorefrontAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_storefront_login_page_renders_successfully(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('Sign In');
        $response->assertSee('Email or Mobile Number');
    }

    public function test_storefront_register_page_renders_successfully(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertSee('Create Buyer Account');
        $response->assertSee('Bangladeshi Mobile Number');
    }

    public function test_customer_can_login_with_valid_email_and_password(): void
    {
        Livewire::test(CustomerAuth::class)
            ->set('loginIdentifier', 'customer@retail.com')
            ->set('loginPassword', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.orders'));

        $this->assertAuthenticated();
        $this->assertEquals('customer@retail.com', auth()->user()->email);
    }

    public function test_customer_can_login_with_valid_phone_number_and_password(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $retailUser->update(['phone' => '01711223344']);

        Livewire::test(CustomerAuth::class)
            ->set('loginIdentifier', '01711223344')
            ->set('loginPassword', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.orders'));

        $this->assertAuthenticated();
        $this->assertEquals($retailUser->id, auth()->id());
    }

    public function test_customer_cannot_login_with_invalid_credentials(): void
    {
        Livewire::test(CustomerAuth::class)
            ->set('loginIdentifier', 'customer@retail.com')
            ->set('loginPassword', 'wrong-password')
            ->call('login')
            ->assertSet('errorMessage', 'Invalid credentials. Please verify your email/phone and password.');

        $this->assertGuest();
    }

    public function test_new_retail_buyer_can_register_and_is_authenticated(): void
    {
        Livewire::test(CustomerAuth::class, ['tab' => 'register'])
            ->set('registerName', 'Rahim Chowdhury')
            ->set('registerPhone', '01899123456')
            ->set('registerEmail', 'rahim@b2bhaat-test.com')
            ->set('registerPassword', 'SecretPass123!')
            ->set('registerPasswordConfirmation', 'SecretPass123!')
            ->set('agreeTerms', true)
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.orders'));

        $this->assertAuthenticated();

        $user = User::where('email', 'rahim@b2bhaat-test.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Rahim Chowdhury', $user->name);
        $this->assertEquals('01899123456', $user->phone);
        $this->assertTrue($user->isRetailBuyer());
    }

    public function test_unauthenticated_guest_is_redirected_to_storefront_login(): void
    {
        $response = $this->get('/my-orders');
        $response->assertRedirect(route('login'));

        $response = $this->get('/my-addresses');
        $response->assertRedirect(route('login'));

        $response = $this->get('/my-wishlist');
        $response->assertRedirect(route('login'));
    }

    public function test_intended_url_redirection_after_login(): void
    {
        // 1. Guest visits /my-orders and gets redirected to /login with intended URL saved in session
        $this->get('/my-orders')
            ->assertRedirect(route('login'));

        // 2. Perform login and ensure redirected to /my-orders
        Livewire::test(CustomerAuth::class)
            ->set('loginIdentifier', 'customer@retail.com')
            ->set('loginPassword', 'password')
            ->call('login')
            ->assertRedirect(route('customer.orders'));
    }
}
