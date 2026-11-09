<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FlashSaleProduct;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use App\Notifications\Payments\PaymentsAwaitingVerification;
use App\Services\Cart\CartSessionService;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PAY-2: unchecked bKash/Nagad payments remind admins after 12 hours and are cancelled as
 * "payment not confirmed" after 72 hours, giving their stock back.
 */
class UnverifiedPaymentTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
            'is_active' => true,
        ]);
    }

    private function bkashOrder(int $hoursAgo): Order
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 2);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Timeout Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'bkash')->value('id'))
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', 'TRXLATE123')
            ->call('placeOrder');

        $order = Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        Order::whereKey($order->id)->update(['created_at' => now()->subHours($hoursAgo)]);

        return $order->fresh();
    }

    private function stockOf(Order $order): int
    {
        return (int) $order->subOrders()->firstOrFail()->items()->firstOrFail()->variation->fresh()->stock_quantity;
    }

    public function test_an_unchecked_payment_reminds_admins_once_and_cancels_nothing(): void
    {
        Notification::fake();
        $order = $this->bkashOrder(hoursAgo: 13);

        $this->artisan('payments:expire-unverified')->assertSuccessful();
        $this->artisan('payments:expire-unverified')->assertSuccessful();

        Notification::assertSentToTimes($this->admin, PaymentsAwaitingVerification::class, 1);
        $this->assertSame('awaiting_verification', $order->fresh()->paymentStatus->slug);
        $this->assertNotNull($order->fresh()->verification_reminded_at);
    }

    public function test_a_payment_unchecked_past_the_limit_is_cancelled_as_not_confirmed_and_stock_returns(): void
    {
        Queue::fake();
        $order = $this->bkashOrder(hoursAgo: 73);
        $stockWhileWaiting = $this->stockOf($order);

        $this->artisan('payments:expire-unverified')->assertSuccessful();

        $order = $order->fresh();
        $this->assertSame('expired', $order->paymentStatus->slug);
        $this->assertSame('cancelled', $order->orderStatus->slug);
        $this->assertSame('cancelled', $order->subOrders()->firstOrFail()->fulfillmentStatus->slug);
        $this->assertSame($stockWhileWaiting + 2, $this->stockOf($order));
        $this->assertSame('TRXLATE123', $order->shipping_address['mfs_transaction_id']);
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => str_contains(serialize($job), 'payment_not_confirmed'));
    }

    public function test_a_payment_still_inside_the_limit_is_left_alone(): void
    {
        $order = $this->bkashOrder(hoursAgo: 2);

        $this->artisan('payments:expire-unverified')->assertSuccessful();

        $this->assertSame('awaiting_verification', $order->fresh()->paymentStatus->slug);
        $this->assertNull($order->fresh()->verification_reminded_at);
    }

    public function test_a_payment_checked_in_the_meantime_is_not_cancelled(): void
    {
        $order = $this->bkashOrder(hoursAgo: 80);
        app(PaymentVerificationService::class)->markVerified($order);

        $this->assertNull(app(PaymentVerificationService::class)->expireUnverified($order, 72));
        $this->assertSame('paid', $order->fresh()->paymentStatus->slug);
    }

    public function test_the_time_limits_are_store_settings(): void
    {
        app(StoreConfigurationService::class)->set('mfs_verification_timeout_hours', 24, 'payments', 'integer');
        $order = $this->bkashOrder(hoursAgo: 25);

        $this->artisan('payments:expire-unverified')->assertSuccessful();

        $this->assertSame('expired', $order->fresh()->paymentStatus->slug);
    }

    public function test_cancelling_an_unchecked_order_frees_its_flash_sale_units(): void
    {
        $order = $this->bkashOrder(hoursAgo: 73);
        $item = $order->subOrders()->firstOrFail()->items()->firstOrFail();
        $offer = FlashSaleProduct::factory()->create(['product_id' => $item->product_id, 'units_sold' => 5]);
        $item->update(['flash_sale_product_id' => $offer->id]);

        $this->artisan('payments:expire-unverified')->assertSuccessful();

        $this->assertSame(3, (int) $offer->fresh()->units_sold);
    }

    public function test_rejecting_a_payment_also_frees_its_flash_sale_units(): void
    {
        $order = $this->bkashOrder(hoursAgo: 1);
        $item = $order->subOrders()->firstOrFail()->items()->firstOrFail();
        $offer = FlashSaleProduct::factory()->create(['product_id' => $item->product_id, 'units_sold' => 5]);
        $item->update(['flash_sale_product_id' => $offer->id]);

        app(PaymentVerificationService::class)->rejectPayment($order, 'TrxID not found in bKash statement');

        $this->assertSame(3, (int) $offer->fresh()->units_sold);
        $this->assertSame('failed', $order->fresh()->paymentStatus->slug);
    }
}
