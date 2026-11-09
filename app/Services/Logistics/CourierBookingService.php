<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Models\SubOrder;
use DomainException;
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
        // shortcut: no courier API is connected yet, so the tracking number below is made up; replace with the
        // chosen courier's real booking API before allowing this outside local/testing (owner decision 2026-11-05).
        if (! app()->environment('local', 'testing')) {
            throw new DomainException('Courier booking is not connected yet. Book the pickup in your courier\'s own app, then use "Dispatch Courier" with the real tracking number.');
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

        // Pickup scheduled = processing, under the shared status rules and lock (ORD-2)
        return app(CourierStatusService::class)->handOver(
            $subOrder,
            'processing',
            ['courier_name' => $courierName, 'tracking_number' => $trackingNumber],
            "Courier Pickup Scheduled ({$courierName})",
            "Rider pickup booked for {$pickupSlot}. Dispatch hub: {$pickupAddress}. Weight/Volume: {$estimatedWeight}. Special Notes: {$instructions}",
            $pickupAddress,
        );
    }
}
