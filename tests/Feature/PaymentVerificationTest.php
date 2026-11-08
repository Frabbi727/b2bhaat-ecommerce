<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Refund;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Cart\CartSessionService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        // bKash and Nagad are only offered once the admin has entered the receiving numbers
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');
        app(StoreConfigurationService::class)->set('nagad_merchant_number', '01911-223344', 'payments');
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    private function checkout(string $methodSlug): Testable
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Verify Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', $methodSlug)->value('id'))
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', 'FAKE000000')
            ->call('placeOrder');
    }

    private function latestOrder(): Order
    {
        return Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    }

    private function bkashOrder(): Order
    {
        $this->checkout('bkash');

        return $this->latestOrder();
    }

    public function test_customer_typed_bkash_trxid_does_not_mark_order_paid(): void
    {
        $order = $this->bkashOrder();

        $this->assertSame('awaiting_verification', $order->paymentStatus->slug);
        $this->assertSame('confirmed', $order->orderStatus->slug);
        $this->assertFalse(WalletTransaction::whereIn('sub_order_id', $order->subOrders->pluck('id'))->exists(), 'Escrow held before payment was verified');
    }

    public function test_mock_gateway_rejected_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $ordersBefore = Order::count();

        $this->checkout('mock_gateway')->assertHasErrors(['selectedPaymentMethodId']);

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_inactive_method_rejected(): void
    {
        PaymentMethod::where('slug', 'nagad')->update(['is_active' => false]);
        $ordersBefore = Order::count();

        $this->checkout('nagad')->assertHasErrors(['selectedPaymentMethodId']);

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_sslcommerz_not_available_yet(): void
    {
        $ordersBefore = Order::count();

        $this->checkout('sslcommerz')->assertHasErrors(['selectedPaymentMethodId']);

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_admin_verify_marks_paid_and_holds_escrow(): void
    {
        $order = $this->bkashOrder();

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('verifyPayment')->table($order));

        $order->refresh();
        $this->assertSame('paid', $order->paymentStatus->slug);
        foreach ($order->subOrders as $subOrder) {
            $this->assertTrue(WalletTransaction::where('sub_order_id', $subOrder->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_HOLD)->exists());
        }
    }

    public function test_verify_action_hidden_for_cod_orders(): void
    {
        $this->checkout('cod');
        $order = $this->latestOrder();

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListOrders::class)
            ->assertActionHidden(TestAction::make('verifyPayment')->table($order));
    }

    public function test_awaiting_verification_not_cancelled_by_reservation_job(): void
    {
        $order = $this->bkashOrder();

        $this->travel(20)->minutes();
        $this->artisan('inventory:release-expired-reservations')->assertSuccessful();

        $this->assertSame('confirmed', $order->fresh()->orderStatus->slug);
    }

    public function test_seller_cannot_dispatch_awaiting_verification_order(): void
    {
        $subOrder = $this->bkashOrder()->subOrders->firstOrFail();

        $this->actingAs($subOrder->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        Livewire::test(ListSubOrders::class)
            ->assertActionHidden(TestAction::make('dispatchCourier')->table($subOrder))
            ->assertActionHidden(TestAction::make('bookCourierPickup')->table($subOrder))
            ->assertActionHidden(TestAction::make('dispatchFactoryFleet')->table($subOrder))
            ->assertActionHidden(TestAction::make('printShippingLabel')->table($subOrder));
    }

    public function test_seller_can_dispatch_cod_order(): void
    {
        $this->checkout('cod');
        $subOrder = $this->latestOrder()->subOrders->firstOrFail();

        $this->actingAs($subOrder->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        Livewire::test(ListSubOrders::class)
            ->assertActionVisible(TestAction::make('dispatchCourier')->table($subOrder));
    }

    public function test_delivery_does_not_mark_unverified_prepaid_order_paid(): void
    {
        $order = $this->bkashOrder();
        $subOrder = $order->subOrders->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id')]);

        app(CourierStatusService::class)->updateStatus($subOrder->fresh(), 'delivered');

        $this->assertSame('awaiting_verification', $order->fresh()->paymentStatus->slug);
    }

    /**
     * PAY-1: a buyer cancels while the bKash payment is still being checked. Once the admin confirms the
     * money arrived, the buyer is owed a refund and no seller escrow is held for the cancelled consignment.
     */
    public function test_verifying_payment_after_buyer_cancelled_records_a_refund_and_holds_no_escrow(): void
    {
        $order = $this->bkashOrder();
        $subOrder = $order->subOrders->firstOrFail();

        app(OrderDisputeService::class)->cancelSubOrder($subOrder, 'Changed my mind');
        $this->assertSame(0, Refund::where('sub_order_id', $subOrder->id)->count(), 'nothing is owed before the money is confirmed');

        app(PaymentVerificationService::class)->markVerified($order);

        $this->assertSame('paid', $order->fresh()->paymentStatus->slug);
        $refund = Refund::where('sub_order_id', $subOrder->id)->first();
        $this->assertNotNull($refund);
        $this->assertSame(round($subOrder->fresh()->paidAmount(), 2), round((float) $refund->amount, 2));
        $this->assertFalse(WalletTransaction::where('sub_order_id', $subOrder->id)->exists());
        $this->assertSame('cancelled', $subOrder->fresh()->fulfillmentStatus->slug);
    }

    public function test_books_owe_the_cancelled_part_to_the_buyer_not_the_seller(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $order = $this->bkashOrder();
        $subOrder = $order->subOrders->firstOrFail();
        app(OrderDisputeService::class)->cancelSubOrder($subOrder, 'Changed my mind');

        app(PaymentVerificationService::class)->markVerified($order);

        $ledger = app(GeneralLedgerService::class);
        $this->assertSame(0.0, $ledger->getBalanceForSlug('seller_escrow_payable', sellerId: $subOrder->seller_id));
        $this->assertSame(0.0, $ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(0.0, $ledger->getBalanceForSlug('shipping_service_revenue'));
        $this->assertSame(round($subOrder->fresh()->paidAmount(), 2), $ledger->getBalanceForSlug('customer_refund_payable'));
    }

    public function test_escrow_is_never_held_for_a_cancelled_consignment(): void
    {
        $subOrder = $this->bkashOrder()->subOrders->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'cancelled')->value('id')]);

        $this->expectException(DomainException::class);

        try {
            app(EscrowLedgerService::class)->holdInEscrow($subOrder->fresh());
        } finally {
            $this->assertFalse(WalletTransaction::where('sub_order_id', $subOrder->id)->exists());
        }
    }
}
