<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Finance\RefundSettledEvent;
use App\Events\Orders\ConsignmentStatusUpdatedEvent;
use App\Events\Orders\OrderPlacedEvent;
use App\Events\Sellers\SellerKycDecidedEvent;
use App\Mail\Customer\OrderConfirmationMail;
use App\Mail\Customer\ParcelStatusUpdateMail;
use App\Mail\Customer\RefundSettledMail;
use App\Mail\Seller\NewConsignmentOrderMail;
use App\Mail\Seller\SellerKycDecisionMail;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EndToEndEmailLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_ecommerce_email_lifecycle(): void
    {
        Mail::fake();

        // 1. Setup multi-vendor merchants
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $vendor1 = User::factory()->create(['role_id' => $sellerRole->id, 'email' => 'vendor1@fabrics.com', 'name' => 'Vendor One']);
        $vendor2 = User::factory()->create(['role_id' => $sellerRole->id, 'email' => 'vendor2@tools.com', 'name' => 'Vendor Two']);

        $profile1 = SellerProfile::create([
            'user_id' => $vendor1->id,
            'store_name' => 'Fabrics Store',
            'store_slug' => 'fabrics-store',
            'is_approved' => false,
        ]);

        // 2. Test Vendor KYC Approval
        event(new SellerKycDecidedEvent($profile1, true));
        Mail::assertQueued(SellerKycDecisionMail::class, fn ($m) => $m->hasTo('vendor1@fabrics.com'));

        // 3. Registered Customer places multi-vendor order
        $customer = User::factory()->create(['email' => 'customer@shoppers.com', 'name' => 'Buyer Ahmed']);
        $status = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $paymentStatus = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $paymentMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash on Delivery']);
        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $order = Order::create([
            'order_number' => 'ORD-'.date('Ymd').'-E2E99',
            'customer_id' => $customer->id,
            'order_status_id' => $status->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'total_amount' => 2500.00,
            'shipping_amount' => 120.00,
            'discount_amount' => 0.00,
            'shipping_address' => [
                'recipient_name' => 'Buyer Ahmed',
                'recipient_phone' => '01711111111',
                'street_address' => 'Gulshan-2',
                'city' => 'Dhaka',
            ],
            'invoice_token' => 'e2e-invoice-token',
            'tracking_token' => 'e2e-tracking-token',
        ]);

        $subOrder1 = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $vendor1->id,
            'sub_order_number' => $order->order_number.'-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 1500.00,
            'commission_amount' => 150.00,
            'shipping_amount' => 60.00,
        ]);

        $subOrder2 = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $vendor2->id,
            'sub_order_number' => $order->order_number.'-B',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 60.00,
        ]);

        // 4. Trigger OrderPlacedEvent
        event(new OrderPlacedEvent($order));

        // Customer receives single aggregated confirmation
        Mail::assertQueued(OrderConfirmationMail::class, fn ($m) => $m->hasTo('customer@shoppers.com'));

        // Both sellers receive isolated notifications
        Mail::assertQueued(NewConsignmentOrderMail::class, fn ($m) => $m->hasTo('vendor1@fabrics.com'));
        Mail::assertQueued(NewConsignmentOrderMail::class, fn ($m) => $m->hasTo('vendor2@tools.com'));

        // 5. Vendor 1 dispatches parcel
        $shipped = FulfillmentStatus::firstOrCreate(['slug' => 'shipped'], ['name' => 'Shipped']);
        $subOrder1->update([
            'fulfillment_status_id' => $shipped->id,
            'courier_name' => 'Pathao Express',
            'tracking_number' => 'PATHAO998877',
        ]);
        event(new ConsignmentStatusUpdatedEvent($subOrder1, 'shipped'));

        Mail::assertQueued(ParcelStatusUpdateMail::class, fn ($m) => $m->hasTo('customer@shoppers.com'));

        // 6. Customer receives refund settlement
        $refund = Refund::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder2->id,
            'amount' => 500.00,
            'reason' => 'Defective consignment item',
            'status' => 'paid',
            'method' => 'bkash',
            'reference' => 'BKASH-E2E-REFUND',
            'paid_at' => now(),
        ]);
        event(new RefundSettledEvent($refund));

        Mail::assertQueued(RefundSettledMail::class, fn ($m) => $m->hasTo('customer@shoppers.com'));
    }
}
