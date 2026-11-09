<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PAY-3 / SEC-5: a bKash or Nagad TrxID pays for one order only.
 */
class MfsTransactionIdUniqueTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');
        app(StoreConfigurationService::class)->set('nagad_merchant_number', '01911-223344', 'payments');
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    private function checkout(string $methodSlug, string $trxId): Testable
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Trx Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', $methodSlug)->value('id'))
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', $trxId)
            ->call('placeOrder');
    }

    public function test_an_order_stores_its_trxid_in_its_own_column(): void
    {
        $this->checkout('bkash', ' 9ab3 cd71ef ');

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('bkash', $order->mfs_gateway);
        $this->assertSame('9AB3CD71EF', $order->mfs_transaction_id);
    }

    public function test_a_trxid_already_used_is_refused_at_checkout(): void
    {
        $this->checkout('bkash', '9AB3CD71EF');
        $ordersBefore = Order::count();

        $this->checkout('bkash', '9ab3cd71ef')
            ->assertHasErrors(['mfsTransactionId'])
            ->assertSee('This TrxID has already been used for another order.');

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_the_same_trxid_on_another_gateway_is_allowed(): void
    {
        $this->checkout('bkash', '9AB3CD71EF');

        $this->checkout('nagad', '9AB3CD71EF')->assertHasNoErrors(['mfsTransactionId']);

        $this->assertSame(2, Order::where('mfs_transaction_id', '9AB3CD71EF')->count());
    }

    public function test_the_database_refuses_a_second_order_with_the_same_trxid(): void
    {
        $this->checkout('bkash', 'DUP12345');
        $first = Order::latest('id')->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        $copy = $first->replicate(['order_number', 'invoice_token', 'tracking_token']);
        $copy->order_number = 'ORD-DUP-COPY';
        $copy->save();
    }

    public function test_cash_on_delivery_orders_need_no_trxid(): void
    {
        $ordersBefore = Order::count();
        $this->checkout('cod', '');
        $this->checkout('cod', '');

        $this->assertSame($ordersBefore + 2, Order::count());
        $this->assertSame(0, Order::whereNotNull('mfs_transaction_id')->count());
    }
}
