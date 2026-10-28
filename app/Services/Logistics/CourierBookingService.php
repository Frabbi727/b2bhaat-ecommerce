<?php

namespace App\Services\Logistics;

use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use Exception;

class CourierBookingService
{
    /**
     * Schedule automated courier pickup for a vendor's sub-order package.
     *
     * @param array{
     *     courier_name: string,
     *     pickup_slot: string,
     *     pickup_address: string,
     *     estimated_weight?: ?string,
     *     instructions?: ?string
     * } $data
     *
     * @throws Exception
     */
    public function schedulePickup(SubOrder $subOrder, array $data): SubOrder
    {
        $currentSlug = $subOrder->fulfillmentStatus?->slug;

        if (in_array($currentSlug, ['delivered', 'returned', 'cancelled'])) {
            throw new Exception("Cannot schedule courier pickup for package in '{$currentSlug}' state.");
        }

        $courierName = $data['courier_name'];
        $pickupSlot = $data['pickup_slot'];
        $pickupAddress = $data['pickup_address'];
        $estimatedWeight = $data['estimated_weight'] ?? '1.0 kg';
        $instructions = $data['instructions'] ?? 'Standard marketplace consignment pickup';

        // Automated courier consignment barcode generator
        $prefix = match ($courierName) {
            'Steadfast Courier' => 'ST',
            'Pathao Courier' => 'PTH',
            'RedX Delivery' => 'RDX',
            'Paperfly' => 'PFLY',
            'Sundarban Courier' => 'SBN',
            default => 'B2B',
        };

        $trackingNumber = $prefix.'-'.now()->format('ymd').'-'.rand(10000, 99999);

        // Transition status to processing (Pickup Scheduled)
        $processingStatus = FulfillmentStatus::where('slug', 'processing')->firstOrFail();

        $subOrder->update([
            'courier_name' => $courierName,
            'tracking_number' => $trackingNumber,
            'fulfillment_status_id' => $processingStatus->id,
        ]);

        // Log logistics event
        OrderTrackingLog::create([
            'sub_order_id' => $subOrder->id,
            'fulfillment_status_id' => $processingStatus->id,
            'title' => "Courier Pickup Scheduled ({$courierName})",
            'description' => "Rider pickup booked for {$pickupSlot}. Dispatch hub: {$pickupAddress}. Weight/Volume: {$estimatedWeight}. Special Notes: {$instructions}",
            'hub_location' => $pickupAddress,
            'logged_at' => now(),
        ]);

        return $subOrder->fresh();
    }
}
