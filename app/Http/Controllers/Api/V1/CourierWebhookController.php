<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Services\Logistics\CourierStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourierWebhookController extends Controller
{
    public function __construct(
        protected CourierStatusService $courierStatusService
    ) {}

    /**
     * Ingest courier status callback / webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => 'required_without:sub_order_number|nullable|string',
            'sub_order_number' => 'required_without:tracking_number|nullable|string',
            'status' => ['required', 'string', Rule::in(array_keys(CourierStatusService::ALLOWED_TRANSITIONS))],
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

        $fulfillmentStatus = FulfillmentStatus::where('slug', $validated['status'])->firstOrFail();

        // Delivery time always comes from the server so it cannot be backdated past the escrow hold window.
        $loggedAt = ($fulfillmentStatus->slug !== 'delivered' && ! empty($validated['timestamp']))
            ? Carbon::parse($validated['timestamp'])
            : now();

        try {
            $this->courierStatusService->updateStatus(
                subOrder: $subOrder,
                statusSlug: $fulfillmentStatus->slug,
                hubLocation: $validated['hub_location'] ?? 'Courier Hub',
                title: $validated['title'] ?? ('Courier Update: '.$fulfillmentStatus->name),
                description: $validated['description'] ?? "Status updated via {$subOrder->courier_name} webhook.",
                timestamp: $loggedAt,
            );
        } catch (InvalidStatusTransitionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => "Sub-order #{$subOrder->sub_order_number} status updated to {$fulfillmentStatus->name}.",
            'sub_order_number' => $subOrder->sub_order_number,
            'current_status' => $fulfillmentStatus->slug,
        ]);
    }
}
