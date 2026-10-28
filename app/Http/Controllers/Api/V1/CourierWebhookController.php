<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourierWebhookController extends Controller
{
    /**
     * Ingest courier status callback / webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => 'required_without:sub_order_number|nullable|string',
            'sub_order_number' => 'required_without:tracking_number|nullable|string',
            'status' => 'required|string', // e.g. 'delivered', 'in_transit', 'out_for_delivery', 'returned'
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'hub_location' => 'nullable|string',
            'timestamp' => 'nullable|date',
        ]);

        $subOrderQuery = SubOrder::query();

        if (! empty($validated['tracking_number'])) {
            $subOrderQuery->where('tracking_number', $validated['tracking_number']);
        } else {
            $subOrderQuery->where('sub_order_number', $validated['sub_order_number']);
        }

        /** @var SubOrder|null $subOrder */
        $subOrder = $subOrderQuery->first();

        if (! $subOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Sub-order consignment not found for provided identifiers.',
            ], 404);
        }

        // Map status
        $statusSlug = strtolower(trim($validated['status']));
        $fulfillmentStatus = FulfillmentStatus::where('slug', $statusSlug)->first()
            ?? FulfillmentStatus::where('slug', 'in_transit')->firstOrFail();

        $subOrder->update([
            'fulfillment_status_id' => $fulfillmentStatus->id,
        ]);

        $loggedAt = ! empty($validated['timestamp'])
            ? Carbon::parse($validated['timestamp'])
            : now();

        OrderTrackingLog::create([
            'sub_order_id' => $subOrder->id,
            'fulfillment_status_id' => $fulfillmentStatus->id,
            'title' => $validated['title'] ?? ('Courier Update: '.$fulfillmentStatus->name),
            'description' => $validated['description'] ?? "Status updated via {$subOrder->courier_name} webhook.",
            'hub_location' => $validated['hub_location'] ?? 'Courier Hub',
            'logged_at' => $loggedAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Sub-order #{$subOrder->sub_order_number} status updated to {$fulfillmentStatus->name}.",
            'sub_order_number' => $subOrder->sub_order_number,
            'current_status' => $fulfillmentStatus->slug,
        ]);
    }
}
