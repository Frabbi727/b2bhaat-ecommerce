<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Wave 1 review M-1 and M-2: the hourly payment expiry run finishes its work even when one order fails or there are
 * many orders, and order and parcel rows are always locked in the same order.
 */
class PaymentExpiryRunTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        Queue::fake();
    }

    private function bkashOrder(string $trxId, int $hoursAgo = 73): Order
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Expiry Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'bkash')->value('id'))
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', $trxId)
            ->call('placeOrder');

        $order = Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        Order::whereKey($order->id)->update(['created_at' => now()->subHours($hoursAgo)]);

        return $order->fresh();
    }

    public function test_one_failing_order_does_not_stop_the_others_from_expiring(): void
    {
        $broken = $this->bkashOrder('TRXBROKEN1');
        $fine = $this->bkashOrder('TRXFINE001');

        $real = app(PaymentVerificationService::class);
        $service = Mockery::mock($real);
        $service->shouldReceive('expireUnverified')->andReturnUsing(function (Order $order, int $hours) use ($real, $broken): ?Order {
            if ($order->id === $broken->id) {
                throw new RuntimeException('Simulated failure');
            }

            return $real->expireUnverified($order, $hours);
        });
        $this->app->instance(PaymentVerificationService::class, $service);

        $this->artisan('payments:expire-unverified')
            ->expectsOutputToContain('1 order(s) could not be cancelled')
            ->assertFailed();

        $this->assertSame('expired', $fine->fresh()->paymentStatus->slug);
        $this->assertSame('awaiting_verification', $broken->fresh()->paymentStatus->slug);
    }

    public function test_every_overdue_order_expires_even_past_one_page(): void
    {
        $template = DB::table('orders')->where('id', $this->bkashOrder('TRXPAGE001')->id)->first();
        $rows = [];
        foreach (range(1, 1000) as $i) {
            $row = (array) $template;
            unset($row['id']);
            $rows[] = array_merge($row, [
                'order_number' => 'ORD-PAGE-'.$i,
                'invoice_token' => Str::random(40),
                'tracking_token' => Str::random(40),
                'mfs_transaction_id' => null,
            ]);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $this->artisan('payments:expire-unverified')->assertSuccessful();

        $awaiting = PaymentStatus::where('slug', 'awaiting_verification')->value('id');
        $this->assertSame(0, Order::where('payment_status_id', $awaiting)->count());
    }

    /**
     * PostgreSQL only: SQLite has no row locks. A buyer cancelling a parcel must lock the order before the parcel,
     * as payment verification and expiry do, so the two can't deadlock.
     */
    public function test_cancelling_a_parcel_locks_the_order_before_the_parcel(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks are only emitted on PostgreSQL.');
        }

        $order = $this->bkashOrder('TRXLOCK001', hoursAgo: 1);
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (preg_match('/from "(orders|sub_orders)".*for update/i', $query->sql, $match)) {
                $locks[] = $match[1];
            }
        });

        app(OrderDisputeService::class)->cancelSubOrder($order->subOrders()->firstOrFail(), 'Changed my mind');

        $this->assertNotEmpty($locks);
        $this->assertSame('orders', $locks[0]);
    }
}
