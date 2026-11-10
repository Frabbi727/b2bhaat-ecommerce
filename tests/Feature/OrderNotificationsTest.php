<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Round 4: buyers hear about every parcel step, sellers hear about orders they can act on,
 * and wholesale applicants hear the decision.
 */
class OrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->seller->forceFill(['phone' => '8801755000111'])->save();
        Queue::fake();
    }

    /** @return list<SendSmsJob> */
    private function smsTo(string $phone, string $purpose): array
    {
        return Queue::pushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->phone === $phone && $job->purpose === $purpose)->all();
    }

    private function setStatus(SubOrder $subOrder, string $slug): void
    {
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $slug)->value('id')]);
    }

    private function placeOrder(string $paymentSlug): void
    {
        $config = app(StoreConfigurationService::class);
        $config->set('bkash_merchant_number', '01811-223344', 'payments');

        $nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        app(CartSessionService::class)->addItem($nails->id, $nails->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);

        $checkout = Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Nusrat Jahan')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'House 12, Road 5, Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', $paymentSlug)->value('id'));

        if ($paymentSlug === 'bkash') {
            $checkout->set('mfsSenderNumber', '01711223344')->set('mfsTransactionId', 'TRX12345AB');
        }

        $this->placeOrderConfirmingPhone($checkout)->assertHasNoErrors();
    }

    // Buyer: one SMS per parcel step that matters
    public function test_buyer_gets_an_sms_when_the_parcel_is_handed_to_the_courier(): void
    {
        $order = MixedOrderFixture::create();
        $parcel = $order->subOrders->firstWhere('subtotal', 500);
        $parcel->update(['courier_name' => 'Steadfast', 'tracking_number' => 'SF-778899']);

        $this->setStatus($parcel, 'shipped');

        $sms = $this->smsTo('8801711000000', 'order_status_update');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString($order->order_number, $sms[0]->message);
        $this->assertStringContainsString('Steadfast', $sms[0]->message);
        $this->assertStringContainsString('SF-778899', $sms[0]->message);
        $this->assertStringContainsString(route('order.track.token', ['token' => $order->tracking_token]), $sms[0]->message);
    }

    public function test_buyer_hears_about_out_for_delivery_delivered_and_cancelled_but_not_internal_steps(): void
    {
        $order = MixedOrderFixture::create();
        $parcel = $order->subOrders->firstWhere('subtotal', 500);

        $this->setStatus($parcel, 'processing');
        $this->setStatus($parcel, 'in_transit');
        $this->assertCount(0, $this->smsTo('8801711000000', 'order_status_update'));

        $this->setStatus($parcel, 'out_for_delivery');
        $this->setStatus($parcel, 'delivered');
        $messages = array_map(fn (SendSmsJob $job) => $job->message, $this->smsTo('8801711000000', 'order_status_update'));
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('out for delivery', $messages[0]);
        $this->assertStringContainsString('delivered', $messages[1]);

        $other = $order->subOrders->firstWhere('subtotal', 1000);
        $this->setStatus($other, 'cancelled');
        $this->assertCount(3, $this->smsTo('8801711000000', 'order_status_update'));
    }

    public function test_saving_a_parcel_without_a_status_change_sends_nothing(): void
    {
        $order = MixedOrderFixture::create();
        $parcel = $order->subOrders->firstWhere('subtotal', 500);

        $parcel->update(['tracking_number' => 'SF-1']);

        $this->assertCount(0, $this->smsTo('8801711000000', 'order_status_update'));
    }

    // Seller: told about orders they can act on
    public function test_seller_is_told_about_a_new_cash_on_delivery_order(): void
    {
        $this->placeOrder('cod');

        $order = Order::latest('id')->firstOrFail();
        $sms = $this->smsTo('8801755000111', 'seller_new_order');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString($order->subOrders->first()->sub_order_number, $sms[0]->message);
        $this->assertStringContainsString('/seller/sub-orders', $sms[0]->message);
    }

    public function test_seller_is_only_told_about_a_bkash_order_after_the_payment_is_checked(): void
    {
        $this->placeOrder('bkash');
        $this->assertCount(0, $this->smsTo('8801755000111', 'seller_new_order'));

        app(PaymentVerificationService::class)->markVerified(Order::latest('id')->firstOrFail());

        $this->assertCount(1, $this->smsTo('8801755000111', 'seller_new_order'));
    }

    // Wholesale applicants
    public function test_wholesale_applicant_is_told_when_approved(): void
    {
        $applicant = User::where('email', 'customer@retail.com')->firstOrFail();
        $applicant->update(['phone' => '8801766000222', 'trade_license_number' => 'TRAD/1', 'business_name' => 'Nusrat Boutique']);

        $applicant->update(['is_wholesale_approved' => true, 'wholesale_approved_at' => now()]);

        $sms = $this->smsTo('8801766000222', 'wholesale_decision');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('approved', $sms[0]->message);
    }

    public function test_wholesale_applicant_is_told_the_reason_when_rejected(): void
    {
        $applicant = User::where('email', 'customer@retail.com')->firstOrFail();
        $applicant->update(['phone' => '8801766000222', 'trade_license_number' => 'TRAD/1']);

        $applicant->update(['wholesale_rejected_at' => now(), 'wholesale_rejection_reason' => 'Trade licence photo is unreadable']);

        $sms = $this->smsTo('8801766000222', 'wholesale_decision');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('Trade licence photo is unreadable', $sms[0]->message);
    }
}
