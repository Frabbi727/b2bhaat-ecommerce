<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\SalesChannel;
use Exception;
use Illuminate\Support\Facades\Session;

class CartSessionService
{
    protected const SESSION_KEY = 'b2bhaat_cart_items';

    protected const COUPON_KEY = 'b2bhaat_applied_coupon';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getItems(): array
    {
        return Session::get(self::SESSION_KEY, []);
    }

    /**
     * Add item with role, MOQ, and progressive volume discount tier validation.
     *
     * @throws Exception
     */
    public function addItem(int $productId, ?int $variationId, string $channelSlug, int $quantity, ?float $overrideUnitPrice = null): void
    {
        $product = Product::with(['prices.salesChannel', 'variations.attributeValues.attribute', 'seller.sellerProfile'])
            ->findOrFail($productId);

        if ($product->seller?->sellerProfile?->is_on_vacation) {
            $storeName = $product->seller->sellerProfile->store_name ?? 'This merchant';
            $vacationNotice = $product->seller->sellerProfile->vacation_message ?: 'The store is temporarily taking a break and cannot accept new orders.';
            throw new Exception("{$storeName} is currently on vacation mode. {$vacationNotice}");
        }

        $variation = $variationId ? $product->variations->firstWhere('id', $variationId) : null;

        // Fetch channel-specific price tiers
        $channelPrices = $product->prices
            ->filter(fn ($p) => $p->salesChannel?->slug === $channelSlug && ($p->variation_id === $variationId || is_null($p->variation_id)))
            ->sortBy('min_quantity');

        if ($channelPrices->isEmpty()) {
            throw new Exception("Pricing for {$channelSlug} channel is unavailable for this item.");
        }

        // Base MOQ is the lowest tier threshold
        $minQty = (int) $channelPrices->first()->min_quantity;

        if ($channelSlug === SalesChannel::WHOLESALE && $quantity < $minQty && is_null($overrideUnitPrice)) {
            throw new Exception("Wholesale minimum order quantity is {$minQty}. You requested {$quantity}.");
        }

        // Match progressive volume tier or apply passed override rate
        $effectivePrice = $overrideUnitPrice;
        $matchedRecord = null;
        if (! $effectivePrice) {
            $matchedRecord = $channelPrices->filter(fn ($p) => $quantity >= $p->min_quantity)->last()
                ?? $channelPrices->first();
            $effectivePrice = (float) $matchedRecord->unit_price;
        } else {
            $matchedRecord = $channelPrices->first();
        }

        $items = $this->getItems();
        $key = "{$productId}_".($variationId ?? 'base')."_{$channelSlug}";

        $sku = $variation ? $variation->sku : ($product->slug.'-BASE');
        $attrs = '';
        if ($variation) {
            $attrs = $variation->attributeValues
                ->map(fn ($av) => "{$av->attribute->name}: {$av->value}")
                ->implode(', ');
        }

        if (isset($items[$key])) {
            $items[$key]['quantity'] += $quantity;
            $items[$key]['unit_price'] = $effectivePrice;
        } else {
            $items[$key] = [
                'key' => $key,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'product_slug' => $product->slug,
                'variation_id' => $variationId,
                'variation_sku' => $sku,
                'attributes_summary' => $attrs,
                'sales_channel_id' => $matchedRecord->sales_channel_id,
                'sales_channel_slug' => $channelSlug,
                'unit_price' => $effectivePrice,
                'min_quantity' => $minQty,
                'quantity' => $quantity,
                'seller_id' => $product->seller_id,
                'seller_name' => $product->seller?->sellerProfile?->store_name ?? $product->seller?->name,
            ];
        }

        Session::put(self::SESSION_KEY, $items);
    }

    public function removeItem(string $key): void
    {
        $items = $this->getItems();
        unset($items[$key]);
        Session::put(self::SESSION_KEY, $items);
    }

    public function updateQuantity(string $key, int $newQuantity): void
    {
        $items = $this->getItems();
        if (! isset($items[$key])) {
            return;
        }

        if ($newQuantity <= 0) {
            $this->removeItem($key);

            return;
        }

        $item = $items[$key];
        if ($item['sales_channel_slug'] === SalesChannel::WHOLESALE && $newQuantity < $item['min_quantity']) {
            throw new Exception("Cannot reduce below wholesale MOQ of {$item['min_quantity']}.");
        }

        $items[$key]['quantity'] = $newQuantity;
        Session::put(self::SESSION_KEY, $items);
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::COUPON_KEY);
    }

    public function applyCoupon(string $code, ?int $userId = null): array
    {
        $couponService = app(CouponService::class);
        $result = $couponService->applyCoupon($code, $this->getSubtotal(), $userId);

        if ($result['valid'] && $result['coupon']) {
            Session::put(self::COUPON_KEY, [
                'id' => $result['coupon']->id,
                'code' => $result['coupon']->code,
                'name' => $result['coupon']->name,
                'discount' => $result['discount'],
            ]);
        }

        return $result;
    }

    public function removeCoupon(): void
    {
        Session::forget(self::COUPON_KEY);
    }

    /**
     * @return array{id: int, code: string, name: string, discount: float}|null
     */
    public function getAppliedCoupon(): ?array
    {
        $couponData = Session::get(self::COUPON_KEY);
        if (! $couponData) {
            return null;
        }

        // Re-verify against current subtotal
        $coupon = Coupon::find($couponData['id']);
        if (! $coupon || ! $coupon->is_active) {
            $this->removeCoupon();

            return null;
        }

        $validation = $coupon->validateForSubtotal($this->getSubtotal(), auth()->id());
        if (! $validation['valid']) {
            $this->removeCoupon();

            return null;
        }

        $discount = $coupon->calculateDiscount($this->getSubtotal());
        $couponData['discount'] = $discount;
        Session::put(self::COUPON_KEY, $couponData);

        return $couponData;
    }

    public function getCoupon(): ?array
    {
        return $this->getAppliedCoupon();
    }

    public function getDiscountAmount(): float
    {
        $coupon = $this->getAppliedCoupon();

        return $coupon ? (float) $coupon['discount'] : 0.00;
    }

    public function getSubtotalAfterDiscount(): float
    {
        return max(0.00, round($this->getSubtotal() - $this->getDiscountAmount(), 2));
    }

    public function getSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += ($item['unit_price'] * $item['quantity']);
        }

        return $total;
    }

    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->getItems() as $item) {
            $count += $item['quantity'];
        }

        return $count;
    }
}
