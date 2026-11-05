<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use Exception;
use Illuminate\Support\Collection;
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
     * Wholesale MOQ and tier pricing apply to the product lot (all its non-quoted wholesale lines) — D7.
     * A line added with an override price is an accepted RFQ quotation: it is flagged `is_quoted` and never re-priced.
     *
     * @throws Exception
     */
    public function addItem(int $productId, ?int $variationId, string $channelSlug, int $quantity, ?float $overrideUnitPrice = null): void
    {
        $this->putItem($productId, $variationId, $channelSlug, $quantity, $overrideUnitPrice, enforceLotMoq: true);

        if ($channelSlug === SalesChannel::WHOLESALE) {
            $this->repriceWholesaleGroup($productId);
        }
    }

    /**
     * Add a wholesale matrix lot (variation id => quantity) in one step; the MOQ applies to the lot total.
     *
     * @param  array<int, int>  $variationQuantities
     *
     * @throws Exception
     */
    public function addWholesaleLot(int $productId, array $variationQuantities): void
    {
        $variationQuantities = array_filter(array_map('intval', $variationQuantities), fn (int $qty) => $qty > 0);
        $lotTotal = $this->lotQuantity($productId) + array_sum($variationQuantities);
        $moq = $this->wholesaleMoqFor($productId);

        if ($moq !== null && $lotTotal < $moq) {
            throw new Exception("Wholesale minimum order quantity is {$moq}. You requested {$lotTotal}.");
        }

        foreach ($variationQuantities as $variationId => $qty) {
            $this->putItem($productId, (int) $variationId, SalesChannel::WHOLESALE, $qty, null, enforceLotMoq: false);
        }

        $this->repriceWholesaleGroup($productId);
    }

    /**
     * @throws Exception
     */
    private function putItem(int $productId, ?int $variationId, string $channelSlug, int $quantity, ?float $overrideUnitPrice, bool $enforceLotMoq): void
    {
        $product = Product::with(['prices.salesChannel', 'variations.attributeValues.attribute', 'seller.sellerProfile'])
            ->sellable()
            ->find($productId);

        if (! $product) {
            throw new Exception('This product is no longer available.');
        }

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

        $items = $this->getItems();
        $key = "{$productId}_".($variationId ?? 'base')."_{$channelSlug}";
        $isQuoted = ! is_null($overrideUnitPrice) || (bool) ($items[$key]['is_quoted'] ?? false);

        if ($channelSlug === SalesChannel::WHOLESALE && $enforceLotMoq && ! $isQuoted) {
            $lotTotal = $this->lotQuantity($productId) + $quantity;
            if ($lotTotal < $minQty) {
                throw new Exception("Wholesale minimum order quantity is {$minQty}. You requested {$lotTotal}.");
            }
        }

        $totalQuantity = $quantity;
        if (isset($items[$key])) {
            $totalQuantity = $items[$key]['quantity'] + $quantity;
        }

        // Match progressive volume tier or apply passed override rate
        $effectivePrice = $overrideUnitPrice ?? ($isQuoted ? (float) $items[$key]['unit_price'] : null);
        $matchedRecord = null;
        if (! $isQuoted) {
            $matchedRecord = $this->resolveTierRecord($channelPrices, $totalQuantity);
            $effectivePrice = (float) $matchedRecord?->unit_price;
        } else {
            $matchedRecord = $channelPrices->first();
        }

        $sku = $variation ? $variation->sku : ($product->slug.'-BASE');
        $attrs = '';
        if ($variation) {
            $attrs = $variation->attributeValues
                ->map(fn ($av) => "{$av->attribute->name}: {$av->value}")
                ->implode(', ');
        }

        if (isset($items[$key])) {
            $items[$key]['quantity'] = $totalQuantity;
            $items[$key]['unit_price'] = $effectivePrice;
            $items[$key]['is_quoted'] = $isQuoted;
        } else {
            $items[$key] = [
                'key' => $key,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'product_slug' => $product->slug,
                'variation_id' => $variationId,
                'variation_sku' => $sku,
                'attributes_summary' => $attrs,
                'sales_channel_id' => $matchedRecord ? $matchedRecord->sales_channel_id : $channelPrices->first()->sales_channel_id,
                'sales_channel_slug' => $channelSlug,
                'unit_price' => $effectivePrice,
                'min_quantity' => $minQty,
                'quantity' => $quantity,
                'is_quoted' => $isQuoted,
                'seller_id' => $product->seller_id,
                'seller_name' => $product->seller?->sellerProfile?->store_name ?? $product->seller?->name,
            ];
        }

        Session::put(self::SESSION_KEY, $items);
    }

    public function removeItem(string $key): void
    {
        $items = $this->getItems();
        $removed = $items[$key] ?? null;
        unset($items[$key]);
        Session::put(self::SESSION_KEY, $items);

        // The remaining lot may drop a tier (or below MOQ — checkout then refuses it).
        if ($removed && $removed['sales_channel_slug'] === SalesChannel::WHOLESALE) {
            $this->repriceWholesaleGroup((int) $removed['product_id']);
        }
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
        $isWholesale = $item['sales_channel_slug'] === SalesChannel::WHOLESALE;

        if ($item['is_quoted'] ?? false) {
            $items[$key]['quantity'] = $newQuantity;
            Session::put(self::SESSION_KEY, $items);

            return;
        }

        if ($isWholesale) {
            $lotTotal = $this->lotQuantity((int) $item['product_id']) - (int) $item['quantity'] + $newQuantity;
            if ($lotTotal < (int) $item['min_quantity']) {
                throw new Exception("Wholesale minimum order quantity is {$item['min_quantity']} for this product (all variations together). This change would leave {$lotTotal}.");
            }

            $items[$key]['quantity'] = $newQuantity;
            Session::put(self::SESSION_KEY, $items);
            $this->repriceWholesaleGroup((int) $item['product_id']);

            return;
        }

        // Dynamically recalculate unit_price to match the updated quantity volume tier
        $product = Product::with(['prices.salesChannel'])->find($item['product_id']);
        if ($product) {
            $channelPrices = $product->prices
                ->filter(fn ($p) => $p->salesChannel?->slug === $item['sales_channel_slug'] && ($p->variation_id === $item['variation_id'] || is_null($p->variation_id)))
                ->sortBy('min_quantity');

            if ($channelPrices->isNotEmpty()) {
                $matchedTier = $this->resolveTierRecord($channelPrices, $newQuantity);
                if ($matchedTier) {
                    $item['unit_price'] = (float) $matchedTier->unit_price;
                }
            }
        }

        $item['quantity'] = $newQuantity;
        $items[$key] = $item;
        Session::put(self::SESSION_KEY, $items);
    }

    /**
     * Re-price every non-quoted wholesale line of a product on the lot total (all variations together).
     */
    public function repriceWholesaleGroup(int $productId): void
    {
        $items = $this->getItems();
        $lotTotal = $this->lotQuantity($productId);
        if ($lotTotal === 0) {
            return;
        }

        $product = Product::with('prices.salesChannel')->find($productId);
        if (! $product) {
            return;
        }

        foreach ($items as $key => $item) {
            if (! $this->isLotLine($item, $productId)) {
                continue;
            }

            $channelPrices = $product->prices
                ->filter(fn ($p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && ($p->variation_id === $item['variation_id'] || is_null($p->variation_id)))
                ->sortBy('min_quantity');

            $tier = $channelPrices->isNotEmpty() ? $this->resolveTierRecord($channelPrices, $lotTotal) : null;
            if ($tier) {
                $items[$key]['unit_price'] = (float) $tier->unit_price;
            }
            $items[$key]['lot_quantity'] = $lotTotal;
        }

        Session::put(self::SESSION_KEY, $items);
    }

    /**
     * Wholesale lots whose total quantity is below the product MOQ (quoted RFQ lines excluded).
     *
     * @return list<array{product_id: int, title: string, quantity: int, moq: int}>
     */
    public function wholesaleMoqViolations(): array
    {
        $violations = [];

        foreach (collect($this->getItems())->filter(fn (array $item) => $this->isLotLine($item))->groupBy('product_id') as $productId => $lines) {
            $quantity = (int) $lines->sum('quantity');
            $moq = (int) $lines->max('min_quantity');
            if ($quantity < $moq) {
                $violations[] = [
                    'product_id' => (int) $productId,
                    'title' => (string) $lines->first()['product_title'],
                    'quantity' => $quantity,
                    'moq' => $moq,
                ];
            }
        }

        return $violations;
    }

    /**
     * Total quantity of a product's non-quoted wholesale lines.
     */
    private function lotQuantity(int $productId): int
    {
        return (int) collect($this->getItems())
            ->filter(fn (array $item) => $this->isLotLine($item, $productId))
            ->sum('quantity');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isLotLine(array $item, ?int $productId = null): bool
    {
        return $item['sales_channel_slug'] === SalesChannel::WHOLESALE
            && ! ($item['is_quoted'] ?? false)
            && ($productId === null || (int) $item['product_id'] === $productId);
    }

    private function wholesaleMoqFor(int $productId): ?int
    {
        $moq = ProductPrice::where('product_id', $productId)
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::WHOLESALE))
            ->min('min_quantity');

        return $moq === null ? null : (int) $moq;
    }

    /**
     * Resolve the effective product price record based on quantity and channel price tiers.
     *
     * @param  Collection<int, ProductPrice>  $channelPrices
     */
    public function resolveTierRecord($channelPrices, int $quantity): ?ProductPrice
    {
        // 1. Check exact min_quantity and max_quantity range
        $matchedRange = $channelPrices->filter(function ($tier) use ($quantity) {
            if ($tier->max_quantity !== null) {
                return $quantity >= $tier->min_quantity && $quantity <= $tier->max_quantity;
            }

            return $quantity >= $tier->min_quantity;
        })->last();

        if ($matchedRange) {
            return $matchedRange;
        }

        // 2. Fallback to progressive threshold: highest min_quantity that $quantity >= min_quantity
        return $channelPrices->filter(fn ($p) => $quantity >= $p->min_quantity)->last()
            ?? $channelPrices->first();
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
