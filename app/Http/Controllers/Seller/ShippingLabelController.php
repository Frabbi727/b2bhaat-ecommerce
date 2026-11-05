<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SubOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingLabelController extends Controller
{
    public function show(Request $request, string $sub_order_number): View
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $subOrder = SubOrder::with([
            'order.paymentMethod',
            'order.paymentStatus',
            'order.orderStatus',
            'seller.sellerProfile',
            'fulfillmentStatus',
            'items.product.unit',
            'items.variation.attributeValues.attribute',
        ])
            ->where('sub_order_number', $sub_order_number)
            ->firstOrFail();

        // Ensure only the assigned seller or super admin can print this shipping label
        if (! $user->isSuperAdmin() && $subOrder->seller_id !== $user->id) {
            abort(403, 'Unauthorized access to consignment label.');
        }

        $order = $subOrder->order;
        $shippingAddress = $order->shipping_address ?? [];

        // Goods + delivery fee − this consignment's coupon share; prepaid orders collect nothing (PRA-051)
        $isPrepaid = $order->paymentMethod?->slug !== 'cod';
        $codAmount = $subOrder->codCollectAmount();

        return view('seller.shipping-label', [
            'subOrder' => $subOrder,
            'order' => $order,
            'shippingAddress' => $shippingAddress,
            'isPrepaid' => $isPrepaid,
            'codAmount' => $codAmount,
        ]);
    }

    public function showChallan(Request $request, string $sub_order_number): View
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $subOrder = SubOrder::with([
            'order.paymentMethod',
            'order.paymentStatus',
            'order.orderStatus',
            'seller.sellerProfile',
            'fulfillmentStatus',
            'items.product.unit',
            'items.variation.attributeValues.attribute',
        ])
            ->where('sub_order_number', $sub_order_number)
            ->firstOrFail();

        // Ensure only assigned seller or super admin can print this delivery challan
        if (! $user->isSuperAdmin() && $subOrder->seller_id !== $user->id) {
            abort(403, 'Unauthorized access to consignment challan.');
        }

        $order = $subOrder->order;
        $shippingAddress = $order->shipping_address ?? [];

        // Goods + delivery fee − this consignment's coupon share; prepaid orders collect nothing (PRA-051)
        $isPrepaid = $order->paymentMethod?->slug !== 'cod';
        $codAmount = $subOrder->codCollectAmount();

        return view('seller.delivery-challan', [
            'subOrder' => $subOrder,
            'order' => $order,
            'shippingAddress' => $shippingAddress,
            'isPrepaid' => $isPrepaid,
            'codAmount' => $codAmount,
        ]);
    }
}
