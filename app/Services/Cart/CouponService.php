<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use DomainException;

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
     * ORD-3: use the coupon for an order, inside the order's transaction. The coupon row is locked and checked again,
     * so two buyers checking out at once can't both take its last use; if it fails, the whole order rolls back.
     *
     * @throws DomainException when the coupon can no longer be used
     */
    public function redeem(int $couponId, int $userId, int $orderId, float $subtotal, float $discountAmount): CouponUsage
    {
        $coupon = Coupon::whereKey($couponId)->lockForUpdate()->first();

        if (! $coupon) {
            throw new DomainException('Promotional coupon cannot be redeemed: it no longer exists. Remove it to place your order.');
        }

        $validation = $coupon->validateForSubtotal($subtotal, $userId);
        if (! $validation['valid']) {
            throw new DomainException("Promotional coupon cannot be redeemed: {$validation['message']} Remove it to place your order.");
        }

        return $this->recordUsage($coupon, $userId, $orderId, $discountAmount);
    }

    /**
     * Record coupon usage when an order is finalized.
     */
    public function recordUsage(Coupon $coupon, int $userId, ?int $orderId, float $discountAmount): CouponUsage
    {
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
        ]);

        $coupon->increment('used_count');

        return $usage;
    }
}
