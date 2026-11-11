<?php

namespace App\Services\Logistics;

use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Services\Sms\OrderNotificationService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class SelfDeliveryService
{
    public const MAX_HANDOVER_ATTEMPTS = 5;

    public const MAX_CODE_RESENDS = 3;

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
        $statusSlug = FulfillmentStatus::where('slug', 'in_transit')->exists() ? 'in_transit' : 'shipped';

        $handoverCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $trackingNumber = 'FD-'.strtoupper(Str::random(8));

        $description = $dispatchNote ?: 'Package is out for direct factory fleet delivery. Secure 6-digit handover verification code issued to recipient.';
        if ($driverContact) {
            $description .= " Fleet driver/rep contact: {$driverContact}.";
        }

        // Same status rules and lock as every other parcel move (ORD-2)
        $this->courierStatusService->handOver(
            $subOrder,
            $statusSlug,
            [
                'is_self_delivered' => true,
                'handover_code' => $handoverCode,
                'courier_name' => 'Factory Direct Fleet',
                'tracking_number' => $trackingNumber,
            ],
            'Dispatched via Factory Direct Fleet',
            $description,
            $hubLocation,
        );

        DB::afterCommit(fn () => $this->textHandoverCode($subOrder));

        return $handoverCode;
    }

    /**
     * Send the buyer their handover code again (lost SMS). Limited so the buyer's phone can't be flooded.
     *
     * @throws Exception when the parcel is not waiting for a handover or the limit is reached
     */
    public function resendHandoverCode(SubOrder $subOrder): void
    {
        if (! $subOrder->is_self_delivered || ! filled($subOrder->handover_code) || $subOrder->fulfillmentStatus?->slug === 'delivered') {
            throw new Exception('This parcel is not waiting for a delivery code.');
        }

        $limiterKey = 'handover-code-resend:'.$subOrder->id;
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_CODE_RESENDS)) {
            throw new Exception('The code was already sent '.self::MAX_CODE_RESENDS.' times in the last hour. Ask the buyer to open their tracking link, or try again later.');
        }

        RateLimiter::hit($limiterKey, 3600);

        if (! app(OrderNotificationService::class)->sendHandoverCode($subOrder->fresh() ?? $subOrder)) {
            throw new Exception('No valid phone number on this order, so the code could not be sent by SMS.');
        }
    }

    private function textHandoverCode(SubOrder $subOrder): void
    {
        try {
            app(OrderNotificationService::class)->sendHandoverCode($subOrder->fresh() ?? $subOrder);
        } catch (Throwable $exception) {
            Log::warning('Handover code SMS not sent: '.$exception->getMessage(), ['sub_order_id' => $subOrder->id]);
        }
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

        // Brute-force guard: 5 wrong codes lock this consignment for an hour (final review C1)
        $limiterKey = 'handover-code:'.$subOrder->id;
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_HANDOVER_ATTEMPTS)) {
            throw new Exception('Too many wrong handover codes for this consignment. Try again later or contact support.');
        }

        if (! is_string($subOrder->handover_code) || ! hash_equals($subOrder->handover_code, trim($inputCode))) {
            RateLimiter::hit($limiterKey, 3600);

            throw new Exception('Invalid Handover Code. Please ask the recipient for the 6-digit verification code shown on their tracking page.');
        }

        RateLimiter::clear($limiterKey);

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
