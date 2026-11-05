<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A two-seller order: seller A self-delivers (fee ৳70), seller B ships by courier (fee ৳70), with a ৳50 coupon.
 *
 * Sub A: subtotal 1000, commission 100. Sub B: subtotal 500, commission 50. Order total = 1500 + 140 − 50 = 1590.
 * Requires SystemLookupSeeder and DemoCatalogSeeder (seller A is seller@nailsfactory.com).
 */
final class MixedOrderFixture
{
    public static function create(string $paymentMethodSlug = 'cod', string $paymentStatusSlug = 'unpaid'): Order
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $sellerB = User::firstOrCreate(['email' => 'second@factory.test'], [
            'role_id' => Role::where('slug', 'seller')->value('id'),
            'name' => 'Second Factory',
            'phone' => '8801799887700',
            'password' => bcrypt(Str::random(32)),
            'is_active' => true,
        ]);

        $number = 'ORD-MIX-'.Str::upper(Str::random(6));
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $paymentStatusSlug)->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $paymentMethodSlug)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000', 'recipient_name' => 'Mixed Buyer'],
            'shipping_amount' => 140.00,
            'discount_amount' => 50.00,
            'coupon_code' => 'SAVE50',
            'total_amount' => 1590.00,
        ]);

        $shipped = FulfillmentStatus::where('slug', 'in_transit')->value('id');

        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => $shipped,
            'courier_name' => 'Factory Direct Fleet',
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 70.00,
            'is_self_delivered' => true,
            'handover_code' => '4321',
        ]);

        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerB->id,
            'sub_order_number' => $number.'-B',
            'fulfillment_status_id' => $shipped,
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'TRK-'.$number,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 70.00,
            'is_self_delivered' => false,
        ]);

        return $order->fresh('subOrders');
    }
}
