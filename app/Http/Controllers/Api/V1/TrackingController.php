<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'number' => 'required|string',
            'phone' => 'required|string',
        ]);

        $orderNumber = trim($request->input('number'));
        $phone = $request->input('phone');

        $query = Order::with([
            'orderStatus',
            'paymentStatus',
            'subOrders.seller.sellerProfile',
            'subOrders.fulfillmentStatus',
            'subOrders.trackingLogs.fulfillmentStatus',
        ])->where('order_number', $orderNumber);

        $query->matchingPhone((string) $phone);

        $order = $query->first();

        if (! $order) {
            return response()->json([
                'message' => "Order #{$orderNumber} was not found.",
            ], 404);
        }

        $packages = $order->subOrders->map(function ($subOrder) {
            return [
                'sub_order_number' => $subOrder->sub_order_number,
                'seller_store' => $subOrder->seller?->sellerProfile?->store_name ?? 'Vendor Store',
                'fulfillment_status' => $subOrder->fulfillmentStatus?->name ?? 'Processing',
                'courier_name' => $subOrder->courier_name,
                'tracking_number' => $subOrder->tracking_number,
                'timeline' => $subOrder->trackingLogs->map(function ($log) {
                    return [
                        'status' => $log->fulfillmentStatus?->name ?? 'Update',
                        'title' => $log->title,
                        'description' => $log->description,
                        'location' => $log->hub_location ?? 'Hub',
                        'timestamp' => $log->logged_at ? $log->logged_at->toISOString() : $log->created_at->toISOString(),
                    ];
                }),
            ];
        });

        return response()->json([
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->orderStatus?->name ?? 'Processing',
                'placed_at' => $order->created_at->toISOString(),
                'packages' => $packages,
            ],
        ]);
    }
}
