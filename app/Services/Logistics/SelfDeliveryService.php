<?php

namespace App\Services\Logistics;

use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use Exception;
use Illuminate\Support\Str;

class SelfDeliveryService
{
    public function __construct(
        protected CourierStatusService $courierStatusService
    ) {}

    /**
     * Dispatch package via factory direct fleet and issue a secure 6-digit handover OTP.
     */
    public function dispatchSelfDelivery(
        SubOrder $subOrder,
        string $hubLocation = 'Factory Dispatch Bay',
        ?string $driverContact = null,
        ?string $dispatchNote = null
    ): string {
        $inTransitStatus = FulfillmentStatus::where('slug', 'in_transit')->first()
            ?? FulfillmentStatus::where('slug', 'shipped')->firstOrFail();

        $handoverCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $trackingNumber = 'FD-'.strtoupper(Str::random(8));

        $subOrder->update([
            'is_self_delivered' => true,
            'handover_code' => $handoverCode,
            'courier_name' => 'Factory Direct Fleet',
            'tracking_number' => $trackingNumber,
            'fulfillment_status_id' => $inTransitStatus->id,
        ]);

        $description = $dispatchNote ?: 'Package is out for direct factory fleet delivery. Secure 6-digit handover verification code issued to recipient.';
        if ($driverContact) {
            $description .= " Fleet driver/rep contact: {$driverContact}.";
        }

        OrderTrackingLog::create([
            'sub_order_id' => $subOrder->id,
            'fulfillment_status_id' => $inTransitStatus->id,
            'title' => 'Dispatched via Factory Direct Fleet',
            'description' => $description,
            'hub_location' => $hubLocation,
            'logged_at' => now(),
        ]);

        return $handoverCode;
    }

    /**
     * Verify customer handover code upon physical receipt and mark delivered.
     *
     * @throws Exception
     */
    public function verifyAndConfirmHandover(SubOrder $subOrder, string $inputCode): void
    {
        if (! $subOrder->is_self_delivered) {
            throw new Exception('This package is not marked for Factory Direct self-delivery.');
        }

        if (trim($inputCode) !== (string) $subOrder->handover_code) {
            throw new Exception('Invalid Handover Code. Please ask the recipient for the 6-digit verification code shown on their tracking page.');
        }

        $subOrder->update([
            'handover_confirmed_at' => now(),
        ]);

        $this->courierStatusService->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'delivered',
            hubLocation: 'Customer Destination',
            title: 'Delivered via Factory Fleet (Code Verified)',
            description: 'Package successfully delivered and confirmed using buyer 6-digit handover verification code.'
        );
    }
}
