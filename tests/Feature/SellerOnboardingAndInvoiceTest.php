<?php

namespace Tests\Feature;

use App\Livewire\Storefront\BecomeSeller;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerOnboardingAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_register_store_via_onboarding_page(): void
    {
        Livewire::test(BecomeSeller::class)
            ->set('storeName', 'Chawkbazar Cosmetics Wholesale')
            ->set('ownerName', 'Mizanur Rahman')
            ->set('email', 'mizan@chawkbazar.com')
            ->set('phone', '+8801722334455')
            ->set('password', 'SecurePass123!')
            ->set('tradeLicense', 'TRAD-CHAWK-09')
            ->set('city', 'Dhaka')
            ->call('registerStore')
            ->assertSet('isSubmitted', true)
            ->assertSee('Application Submitted Successfully!');

        $user = User::where('email', 'mizan@chawkbazar.com')->firstOrFail();
        $this->assertEquals('seller', $user->role->slug);

        $profile = SellerProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertEquals('Chawkbazar Cosmetics Wholesale', $profile->store_name);
        $this->assertFalse($profile->is_approved); // Pending admin approval
    }

    public function test_can_view_and_print_commercial_tax_invoice(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-INV-2026',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'mock_gateway')->value('id'),
            'shipping_address' => [
                'recipient_name' => 'Tanvir Hasan',
                'recipient_phone' => '+8801700000004',
                'street_address' => 'House 14, Road 5, Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1205',
            ],
            'total_amount' => 500.00,
        ]);

        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-INV-2026-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-INV-99',
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
        ]);

        $response = $this->get('/order/ORD-INV-2026/invoice');

        $response->assertStatus(200)
            ->assertSee('TAX INVOICE / CHALLAN')
            ->assertSee('ORD-INV-2026')
            ->assertSee('NailsFactory Ltd.')
            ->assertSee('Steadfast Courier');
    }
}
