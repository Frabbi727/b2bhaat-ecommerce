<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function show(string $orderNumber): View
    {
        $order = Order::with([
            'orderStatus',
            'paymentStatus',
            'paymentMethod',
            'customer',
            'subOrders.seller.sellerProfile',
            'subOrders.items.product',
            'subOrders.items.variation.attributeValues.attribute',
        ])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return view('storefront.invoice', [
            'order' => $order,
        ]);
    }
}
