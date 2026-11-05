<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Widgets\AdminFulfillmentDistributionChart;
use App\Filament\Admin\Widgets\AdminOrdersRevenueChart;
use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Admin\Widgets\LatestOrdersWidget;
use App\Filament\Seller\Widgets\SellerRecentOrdersWidget;
use App\Filament\Seller\Widgets\SellerRevenueChart;
use App\Filament\Seller\Widgets\SellerStatsOverview;
use App\Filament\Seller\Widgets\SellerStockAlertsWidget;
use App\Livewire\Storefront\CustomerOrders;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class RoleBasedAccessControlAndDashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_customer_cannot_view_another_customers_invoice(): void
    {
        $customerA = User::where('email', 'customer@retail.com')->firstOrFail();
        $customerB = User::firstOrCreate(
            ['email' => 'other.buyer@example.com'],
            [
                'role_id' => Role::where('slug', 'retail_buyer')->firstOrFail()->id,
                'name' => 'Other Buyer',
                'phone' => '+8801999999999',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        // Find or associate an order with customer A
        $order = Order::firstOrFail();
        $order->update(['customer_id' => $customerA->id]);

        // Customer B tries to view Customer A's invoice
        $this->actingAs($customerB);
        $responseB = $this->get(route('order.invoice', $order->order_number));
        $responseB->assertForbidden();

        // Customer A can view their own invoice
        $this->actingAs($customerA);
        $responseA = $this->get(route('order.invoice', $order->order_number));
        $responseA->assertSuccessful();
    }

    public function test_assigned_seller_can_view_invoice_but_unrelated_seller_is_forbidden(): void
    {
        $sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $sellerB = User::where('email', 'haier.official@b2bhaat.com')->firstOrFail();
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();

        $order = Order::firstOrFail();

        // Make sure only seller A is in sub-orders of this order
        SubOrder::where('parent_order_id', $order->id)->update(['seller_id' => $sellerA->id]);

        // Seller A (assigned) can view
        $this->actingAs($sellerA);
        $this->get(route('order.invoice', $order->order_number))->assertSuccessful();

        // Seller B (unrelated) is forbidden
        $this->actingAs($sellerB);
        $this->get(route('order.invoice', $order->order_number))->assertForbidden();

        // Super Admin can view
        $this->actingAs($admin);
        $this->get(route('order.invoice', $order->order_number))->assertSuccessful();
    }

    public function test_retail_user_cannot_bypass_wholesale_matrix_cart_gate(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variations = $product->variations()->get();

        $this->actingAs($retailUser);

        $cart = app(CartSessionService::class);
        $cart->clear();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variations[0]->id}", 50)
            ->call('addBulkMatrixToCart')
            ->assertSee('Wholesale volume tier sourcing is reserved for verified wholesale business accounts');

        $this->assertEmpty($cart->getItems());
    }

    public function test_customer_dashboard_renders_real_aggregated_metrics(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->actingAs($customer);

        Livewire::test(CustomerOrders::class)
            ->assertSuccessful()
            ->assertViewHas('totalOrdersCount')
            ->assertViewHas('totalSpentAmount')
            ->assertViewHas('inTransitCount');
    }

    public function test_guest_is_redirected_when_accessing_my_orders(): void
    {
        $response = $this->get(route('customer.orders'));
        $response->assertRedirect();
    }

    public function test_admin_and_seller_dashboard_widgets_render_successfully(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(AdminStatsOverview::class)->assertSuccessful();
        Livewire::test(AdminOrdersRevenueChart::class)->assertSuccessful();
        Livewire::test(AdminFulfillmentDistributionChart::class)->assertSuccessful();
        Livewire::test(LatestOrdersWidget::class)->assertSuccessful();

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($seller);

        Livewire::test(SellerStatsOverview::class)->assertSuccessful();
        Livewire::test(SellerRevenueChart::class)->assertSuccessful();
        Livewire::test(SellerStockAlertsWidget::class)->assertSuccessful();
        Livewire::test(SellerRecentOrdersWidget::class)->assertSuccessful();
    }

    public function test_customer_dashboard_can_filter_by_status(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($customer);

        Livewire::test(CustomerOrders::class)
            ->set('statusFilter', 'completed')
            ->assertSet('statusFilter', 'completed')
            ->assertSuccessful();
    }
}
