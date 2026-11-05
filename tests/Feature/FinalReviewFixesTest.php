<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Payments\PaymentVerificationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Regression tests for the Important findings of the Phase B whole-branch review (I1–I7, M3, M6).
 */
class FinalReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    private function setStatus(SubOrder $subOrder, string $slug): SubOrder
    {
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $slug)->value('id')]);

        return $subOrder->fresh();
    }

    /** Courier sub-order B with one stocked order item. */
    private function courierSubOrderWithItem(Order $order): array
    {
        $sub = $order->subOrders->firstWhere('subtotal', 500);
        $variation = ProductVariation::firstOrFail();
        OrderItem::create([
            'sub_order_id' => $sub->id,
            'product_id' => $variation->product_id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'quantity' => 2,
            'unit_price' => 250,
            'total_price' => 500,
        ]);

        return [$sub->fresh(), $variation];
    }

    // I1 — approved replacement must not strand the escrow hold
    public function test_approved_replacement_keeps_delivery_and_escrow_releases(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        $sub = $order->subOrders->firstWhere('subtotal', 500);
        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($sub->fresh(), User::findOrFail($order->customer_id), [
            'reason' => 'Wrong size', 'requested_action' => 'replacement', 'description' => 'Need L',
        ]);
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Replacement approved');

        $this->assertSame('delivered', $sub->fresh()->fulfillmentStatus->slug);
        $this->assertSame(0, Refund::count());

        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertTrue(WalletTransaction::where('sub_order_id', $sub->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists());
    }

    // I2 — couriers may not cancel through the webhook (no side effects were run)
    public function test_courier_webhook_cannot_cancel(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        $sub = $this->setStatus($order->subOrders->firstWhere('subtotal', 500), 'processing');

        $this->signedCourierPost(['tracking_number' => $sub->tracking_number, 'status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('processing', $sub->fresh()->fulfillmentStatus->slug);
    }

    // I3 — courier return of a prepaid order records what the buyer is owed
    public function test_courier_rto_of_paid_order_records_refund(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        $sub = $order->subOrders->firstWhere('subtotal', 500);

        app(CourierStatusService::class)->updateStatus($sub, 'returned');

        $refund = Refund::sole();
        $this->assertSame($sub->id, $refund->sub_order_id);
        $this->assertEqualsWithDelta(553.33, (float) $refund->amount, 0.001);
    }

    // I4 — admin can reject a fake bKash/Nagad TrxID and the stock comes back
    public function test_admin_rejects_unverified_payment_and_releases_stock(): void
    {
        $order = MixedOrderFixture::create('bkash', 'awaiting_verification');
        $this->setStatus($order->subOrders->firstWhere('subtotal', 1000), 'unfulfilled');
        [$sub, $variation] = $this->courierSubOrderWithItem($order);
        $this->setStatus($sub, 'unfulfilled');
        $stockBefore = $variation->fresh()->stock_quantity;

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('rejectPayment')->table($order), ['reason' => 'TrxID not found in bKash statement'])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame('failed', $order->paymentStatus->slug);
        $this->assertSame('cancelled', $order->orderStatus->slug);
        $this->assertTrue($order->subOrders->every(fn (SubOrder $s) => $s->fresh()->fulfillmentStatus->slug === 'cancelled'));
        $this->assertSame($stockBefore + 2, $variation->fresh()->stock_quantity);

        $this->expectException(Exception::class);
        app(PaymentVerificationService::class)->rejectPayment($order, 'again');
    }

    // I5 — backslash tricks must not become an open redirect
    public function test_login_redirect_rejects_backslash_and_control_characters(): void
    {
        foreach (['/\\evil.example', '/%5Cevil.example', "/\tevil", '\\\\evil.example'] as $redirect) {
            session()->forget('url.intended');
            $this->get(route('login', ['redirect' => rawurldecode($redirect)]))->assertOk();
            $this->assertNull(session('url.intended'), "Accepted unsafe redirect: {$redirect}");
        }

        $this->get(route('login', ['redirect' => '/product/x']))->assertOk();
        $this->assertSame('/product/x', session('url.intended'));
    }

    // I6 — accepted quote is pinned to its negotiated quantity; only an open quote can be accepted, once
    public function test_quoted_line_quantity_is_fixed_and_quote_accepted_once(): void
    {
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $rfq = RfqRequest::create([
            'product_id' => $product->id, 'seller_id' => $product->seller_id, 'buyer_id' => $buyer->id,
            'buyer_name' => 'B', 'buyer_email' => $buyer->email, 'buyer_phone' => '01711000000',
            'target_quantity' => 500, 'destination_district' => 'Dhaka', 'status' => 'quoted', 'seller_quoted_price' => 70,
        ]);

        $this->actingAs($buyer)->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect();
        $this->actingAs($buyer)->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect();

        $cart = app(CartSessionService::class);
        $key = "{$product->id}_base_".SalesChannel::WHOLESALE;
        $this->assertSame(500, $cart->getItems()[$key]['quantity'], 'Accepting twice must not double the line');

        try {
            $cart->updateQuantity($key, 1);
            $this->fail('Quoted quantity must be fixed');
        } catch (Exception $exception) {
            $this->assertStringContainsString('quoted', strtolower($exception->getMessage()));
        }
        $this->assertSame(500, $cart->getItems()[$key]['quantity']);

        // A declined RFQ with a stale price cannot be accepted
        $declined = $rfq->replicate();
        $declined->status = 'declined';
        $declined->save();
        $cart->clear();
        $this->actingAs($buyer)->post(route('rfq.quotation.accept', $declined->id))->assertRedirect();
        $this->assertSame([], $cart->getItems());
        $this->assertSame('declined', $declined->fresh()->status);
    }

    // I7 — a returned consignment cannot be cancelled (double restock)
    public function test_returned_sub_order_cannot_be_cancelled(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$sub, $variation] = $this->courierSubOrderWithItem($order);
        $sub = $this->setStatus($sub, 'returned');
        $stockBefore = $variation->fresh()->stock_quantity;

        try {
            app(OrderDisputeService::class)->cancelSubOrder($sub, 'late cancel');
            $this->fail('Returned consignment must not be cancellable');
        } catch (Exception) {
            // expected
        }

        $this->assertSame('returned', $sub->fresh()->fulfillmentStatus->slug);
        $this->assertSame($stockBefore, $variation->fresh()->stock_quantity);
    }

    // M6 (re-graded Important) — deactivated users cannot get or keep API tokens
    public function test_deactivated_user_cannot_use_api(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $customer->createToken('existing');

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListUsers::class)->callAction(TestAction::make('deactivate')->table($customer));

        $this->assertSame(0, $customer->tokens()->count(), 'Deactivation revokes existing API tokens');

        $this->postJson('/api/v1/auth/login', ['email' => 'customer@retail.com', 'password' => 'password'])
            ->assertStatus(422);
    }

    // M3 (re-graded Important) — saving the product form must not undo stock sold while it was open
    public function test_editing_simple_product_without_touching_stock_keeps_live_stock(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $source = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $product = $source->replicate(['slug', 'sku']);
        $product->slug = 'simple-race';
        $product->sku = 'RACE-1';
        $product->has_variations = false;
        $product->save();
        foreach ($source->prices()->whereNull('variation_id')->get() as $price) {
            $copy = $price->replicate();
            $copy->product_id = $product->id;
            $copy->save();
        }
        $product->variations()->update(['stock_quantity' => 7]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $form = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

        // Two units sell while the form is open
        $product->variations()->update(['stock_quantity' => 5]);

        $form->fillForm(['title' => 'Renamed'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(5, $product->variations()->sole()->stock_quantity);
    }

    public function test_seller_can_still_change_simple_product_stock(): void
    {
        $this->test_editing_simple_product_without_touching_stock_keeps_live_stock();
        $product = Product::where('slug', 'simple-race')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['simple_stock_quantity' => 40])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(40, $product->variations()->sole()->stock_quantity);
    }
}
