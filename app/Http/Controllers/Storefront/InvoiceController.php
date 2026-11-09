<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function show(Request $request, string $orderNumber): View
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

        $user = $request->user();

        // If an authenticated user is logged in, ensure tenant and user data isolation
        if ($user) {
            $isAdmin = in_array($user->role?->slug, ['super_admin', 'admin'], true);
            $isCustomer = $order->customer_id === $user->id;
            $isAssignedSeller = $order->subOrders->contains(fn ($sub) => $sub->seller_id === $user->id);

            if (! $isAdmin && ! $isCustomer && ! $isAssignedSeller) {
                abort(403, 'Unauthorized. You do not have permission to view this commercial tax invoice.');
            }

            // SEC-9: a seller sees only their own packages, never another seller's lines or the order total.
            if (! $isAdmin && ! $isCustomer) {
                $order->setRelation('subOrders', $order->subOrders->where('seller_id', $user->id)->values());

                return view('storefront.invoice', [
                    'order' => $order,
                    'sellerScoped' => true,
                ]);
            }
        }

        return view('storefront.invoice', [
            'order' => $order,
        ]);
    }

    /**
     * Show invoice securely via unique token without requiring login.
     */
    public function showByToken(string $token): View
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
            ->where('invoice_token', $token)
            ->firstOrFail();

        abort_if($order->invoiceLinkExpired(), 410, 'This invoice link has expired. Sign in to your account to view the invoice.');

        return view('storefront.invoice', [
            'order' => $order,
        ]);
    }
}
