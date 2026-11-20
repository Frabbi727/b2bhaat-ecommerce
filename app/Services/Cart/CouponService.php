<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * Validate and calculate discount for a coupon code on given subtotal.
     *
     * @return array{
     *     valid: bool,
     *     message: string,
     *     coupon: ?Coupon,
     *     discount: float
     * }
     */
    public function applyCoupon(string $code, float $subtotal, ?int $userId = null): array
    {
        $code = strtoupper(trim($code));

        if (empty($code)) {
            return [
                'valid' => false,
                'message' => 'Please enter a coupon code.',
                'coupon' => null,
                'discount' => 0.00,
            ];
        }

        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon) {
            return [
                'valid' => false,
                'message' => 'Invalid promotional coupon code.',
                'coupon' => null,
                'discount' => 0.00,
            ];
        }

        $validation = $coupon->validateForSubtotal($subtotal, $userId);

        if (! $validation['valid']) {
            return [
                'valid' => false,
                'message' => $validation['message'],
                'coupon' => null,
                'discount' => 0.00,
            ];
        }

        $discount = $coupon->calculateDiscount($subtotal);

        return [
            'valid' => true,
            'message' => "Coupon '{$coupon->code}' applied: ৳".number_format($discount, 2).' discount!',
            'coupon' => $coupon,
            'discount' => $discount,
        ];
    }

    /**
     * ORD-3 / WA-CHK-01: lock the coupon row inside the order's transaction, check it again and price it. The caller
     * redeems this same row with recordUsage(), so two buyers checking out at once can't both take its last use.
     *
     * @return array{coupon: Coupon, discount: float}
     *
     * @throws DomainException when the coupon can no longer be used
     */
    public function lockForOrder(int $couponId, int $userId, float $subtotal): array
    {
        $coupon = Coupon::whereKey($couponId)->lockForUpdate()->first();

        if (! $coupon) {
            throw new DomainException(self::removedMessage('it no longer exists.'));
        }

        $validation = $coupon->validateForSubtotal($subtotal, $userId);
        if (! $validation['valid']) {
            throw new DomainException(self::removedMessage($validation['message']));
        }

        return ['coupon' => $coupon, 'discount' => $coupon->calculateDiscount($subtotal)];
    }

    private static function removedMessage(string $reason): string
    {
        return "Promotional coupon cannot be redeemed: {$reason} We removed it from your cart. Press Place order again to order without it.";
    }

    /**
     * Record coupon usage when an order is finalized.
     */
    /**
     * AR-04 (owner decision 2026-11-19): an order that ended fully cancelled gives its coupon use back, so the buyer
     * can use a one-per-customer coupon again and the use frees a place under the total limit. Given back once.
     */
    public function releaseForOrder(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $usages = CouponUsage::where('order_id', $order->id)->whereNull('reversed_at')->lockForUpdate()->get();

            foreach ($usages as $usage) {
                $usage->update(['reversed_at' => now()]);
                Coupon::whereKey($usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }
        });
    }

    public function recordUsage(Coupon $coupon, int $userId, ?int $orderId, float $discountAmount): CouponUsage
    {
        // The total limit is enforced by the update itself, so a use can never be counted past it
        $counted = Coupon::whereKey($coupon->id)
            ->where(fn ($query) => $query->whereNull('usage_limit_total')->orWhere('usage_limit_total', 0)->orWhereColumn('used_count', '<', 'usage_limit_total'))
            ->increment('used_count');

        if ($counted === 0) {
            throw new DomainException(self::removedMessage('This coupon has reached its maximum total usage limit.'));
        }

        $coupon->used_count++;
        $coupon->syncOriginalAttribute('used_count');

        return CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
        ]);
    }
}
