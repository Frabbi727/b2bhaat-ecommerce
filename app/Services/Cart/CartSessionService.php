<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Services\Formatting\Money;
use App\Services\Promotions\FlashSalePricing;
use DomainException;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
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
        return $this->moveLegacyQuotedLines(Session::get(self::SESSION_KEY, []));
    }

    /**
     * Cart key of an accepted RFQ quotation. It never equals a normal line's key, so a normal add of the same product
     * can't merge into it and inherit the negotiated price (WA-PRC-01). A line from before RFQ ids were stored has
     * rfq id `legacy`.
     */
    public static function quotedLineKey(int $productId, ?int $variationId, ?int $rfqId, string $channelSlug = SalesChannel::WHOLESALE): string
    {
        return "{$productId}_".($variationId ?? 'base')."_{$channelSlug}_quote_".($rfqId ?? 'legacy');
    }

    /**
     * WA-PRC-01: carts saved before the fix kept a quoted line under the normal key. Move it to its own quoted key
     * (no RFQ id is known for it) so it keeps its price and a normal add gets a line of its own.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function moveLegacyQuotedLines(array $items): array
    {
        $moved = false;

        foreach ($items as $key => $item) {
            if (($item['is_quoted'] ?? false) && ! str_contains((string) $key, '_quote_')) {
                $variationId = isset($item['variation_id']) ? (int) $item['variation_id'] : null;
                $newKey = self::quotedLineKey((int) $item['product_id'], $variationId, null, (string) $item['sales_channel_slug']);
                unset($items[$key]);
                $items[$newKey] = [...$item, 'key' => $newKey, 'rfq_id' => null];
                $moved = true;
            }
        }

        if ($moved) {
            Session::put(self::SESSION_KEY, $items);
        }

        return $items;
    }

    /**
     * Add item with role, MOQ, and progressive volume discount tier validation.
     * Wholesale MOQ and tier pricing apply to the product lot (all its non-quoted wholesale lines) — D7.
     * A line added with an override price is an accepted RFQ quotation: it gets its own line (see quotedLineKey()),
     * is flagged `is_quoted` and is never re-priced.
     *
     * @throws Exception
     */
    public function addItem(int $productId, ?int $variationId, string $channelSlug, int $quantity, ?float $overrideUnitPrice = null, ?int $rfqId = null): void
    {
        $this->putItem($productId, $variationId, $channelSlug, $quantity, $overrideUnitPrice, enforceLotMoq: true, rfqId: $rfqId);

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
        $moq = $this->wholesaleMinimumQuantity($productId);

        if ($moq !== null && $lotTotal < $moq) {
            throw new Exception("Wholesale minimum order quantity is {$moq}. You requested {$lotTotal}.");
        }

        // INV-5: check every option first, so a lot with one switched-off option adds nothing
        $product = Product::with('variations')->find($productId);
        if ($product) {
            foreach (array_keys($variationQuantities) as $variationId) {
                $this->assertSellableOption($product, (int) $variationId);
            }
        }

        foreach ($variationQuantities as $variationId => $qty) {
            $this->putItem($productId, (int) $variationId, SalesChannel::WHOLESALE, $qty, null, enforceLotMoq: false);
        }

        $this->repriceWholesaleGroup($productId);
    }

    /**
     * @throws Exception
     */
    /**
     * INV-5: the option must belong to the product and, on a product with options, still be switched on (Sellable).
     * A simple product's hidden default option has no Sellable switch, so it is not checked.
     *
     * @throws DomainException
     */
    private function assertSellableOption(Product $product, ?int $variationId): ?ProductVariation
    {
        if ($variationId === null) {
            return null;
        }

        $variation = $product->variations->firstWhere('id', $variationId);

        if (! $variation) {
            throw new DomainException('This option is not part of this product.');
        }

        if ($product->has_variations && ! $variation->is_active) {
            throw new DomainException("Option {$variation->sku} is no longer sold. Please choose another option.");
        }

        return $variation;
    }

    private function putItem(int $productId, ?int $variationId, string $channelSlug, int $quantity, ?float $overrideUnitPrice, bool $enforceLotMoq, ?int $rfqId = null): void
    {
        // WA-CHK-02: every add goes through here, so a zero or negative quantity can never lower a cart
        if ($quantity < 1) {
            throw new DomainException('Quantity must be at least 1.');
        }

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

        // Simple products are sold through their default variation so stock is reserved (PRA-011)
        if ($variationId === null && ! $product->has_variations) {
            $variationId = $product->variations->sortBy('id')->first()?->id;
            if ($variationId === null) {
                throw new Exception('This product is out of stock.');
            }
        }

        $variation = $this->assertSellableOption($product, $variationId);

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
        // A line is quoted only when added with the quote's price; a normal add never lands on (or inherits) a quote
        $isQuoted = ! is_null($overrideUnitPrice);
        $key = $isQuoted
            ? self::quotedLineKey($productId, $variationId, $rfqId, $channelSlug)
            : "{$productId}_".($variationId ?? 'base')."_{$channelSlug}";

        // One accepted quotation is one line at its negotiated quantity: adding it again changes nothing (I6)
        if ($isQuoted && isset($items[$key])) {
            return;
        }

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
        if (! $isQuoted) {
            $matchedRecord = $this->resolveTierRecord($channelPrices, $totalQuantity);
            $pricing = $this->applyFlashSale($product, $channelSlug, (float) $matchedRecord?->unit_price);
            if ($pricing['unit_price'] <= 0) {
                throw new Exception('This product has no valid price yet and cannot be ordered.');
            }
        } else {
            $matchedRecord = $channelPrices->first();
            $pricing = $this->tierOnlyPrice((float) $overrideUnitPrice);
        }

        $sku = $variation ? $variation->sku : ($product->slug.'-BASE');
        $attrs = '';
        if ($variation) {
            $attrs = $variation->attributeValues
                ->map(fn ($av) => "{$av->attribute->name}: {$av->value}")
                ->implode(', ');
        }

        if (isset($items[$key])) {
            $items[$key] = [...$items[$key], ...$pricing];
            $items[$key]['quantity'] = $totalQuantity;
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
                ...$pricing,
                'min_quantity' => $minQty,
                'quantity' => $quantity,
                'is_quoted' => $isQuoted,
                'rfq_id' => $isQuoted ? $rfqId : null,
                'seller_id' => $product->seller_id,
                'seller_name' => $product->seller?->sellerProfile?->store_name ?? $product->seller?->name,
            ];
        }

        Session::put(self::SESSION_KEY, $items);
    }

    /** Take an accepted quotation's line back out of the cart, e.g. when the acceptance lost a race. */
    public function removeQuotedLine(int $rfqId): void
    {
        $items = $this->getItems();
        $kept = array_filter($items, fn (array $item) => ! (($item['is_quoted'] ?? false) && ($item['rfq_id'] ?? null) === $rfqId));

        if (count($kept) !== count($items)) {
            Session::put(self::SESSION_KEY, $kept);
        }
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

        // An accepted RFQ quote is priced for its negotiated quantity: it can be removed, not resized (I6)
        if ($item['is_quoted'] ?? false) {
            if ($newQuantity !== (int) $item['quantity']) {
                throw new Exception('This line is a quoted price for a fixed quantity. Remove it or request a new quotation.');
            }

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
            $item = [...$item, ...($this->linePricing($product, $item, $newQuantity) ?? [])];
        }

        $item['quantity'] = $newQuantity;
        $items[$key] = $item;
        Session::put(self::SESSION_KEY, $items);
    }

    /**
     * Re-price every non-quoted line at today's tier and live campaign price, so a flash price
     * that has ended (or a campaign that was paused) is never charged from an old cart.
     *
     * @return array<int, string> titles of the products whose price changed
     */
    public function refreshPrices(): array
    {
        $before = $this->getItems();
        if ($before === []) {
            return [];
        }

        $pricing = app(FlashSalePricing::class);
        $pricing->flush();

        $products = Product::with('prices.salesChannel')
            ->whereIn('id', collect($before)->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');
        $pricing->primeFor($products);

        $items = $before;
        $wholesaleProductIds = [];
        foreach ($items as $key => $item) {
            if ($item['is_quoted'] ?? false) {
                continue;
            }

            if ($item['sales_channel_slug'] === SalesChannel::WHOLESALE) {
                $wholesaleProductIds[(int) $item['product_id']] = (int) $item['product_id'];

                continue;
            }

            $product = $products->get($item['product_id']);
            if ($product) {
                $items[$key] = [...$item, ...($this->linePricing($product, $item, (int) $item['quantity']) ?? [])];
            }
        }

        Session::put(self::SESSION_KEY, $items);

        foreach ($wholesaleProductIds as $productId) {
            $this->repriceWholesaleGroup($productId);
        }

        $changedTitles = [];
        foreach ($this->getItems() as $key => $item) {
            if (abs((float) $item['unit_price'] - (float) $before[$key]['unit_price']) > 0.005) {
                $changedTitles[] = (string) $item['product_title'];
            }
        }

        return array_values(array_unique($changedTitles));
    }

    /**
     * Tier price for the line at $quantity, with the live campaign price for retail lines.
     *
     * @param  array<string, mixed>  $item
     * @return array{unit_price: float, flash_sale_product_id: ?int, original_unit_price: ?float}|null
     */
    private function linePricing(Product $product, array $item, int $quantity): ?array
    {
        $channelPrices = $product->prices
            ->filter(fn ($p) => $p->salesChannel?->slug === $item['sales_channel_slug'] && ($p->variation_id === $item['variation_id'] || is_null($p->variation_id)))
            ->sortBy('min_quantity');

        if ($channelPrices->isEmpty()) {
            return null;
        }

        $matchedTier = $this->resolveTierRecord($channelPrices, $quantity);

        return $matchedTier
            ? $this->applyFlashSale($product, $item['sales_channel_slug'], (float) $matchedTier->unit_price)
            : null;
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
                $items[$key] = [...$items[$key], ...$this->tierOnlyPrice((float) $tier->unit_price)];
            }
            $items[$key]['lot_quantity'] = $lotTotal;
        }

        Session::put(self::SESSION_KEY, $items);
    }

    /**
     * Titles of cart items that can no longer be bought: hidden by the seller or the admin,
     * the seller account turned off or not approved, or the store on vacation.
     *
     * @return list<string>
     */
    public function unavailableItemTitles(): array
    {
        $items = collect($this->getItems());
        $buyableProductIds = Product::query()
            ->sellable()
            ->whereIn('id', $items->pluck('product_id')->unique())
            ->whereHas('seller.sellerProfile', fn ($profile) => $profile->where('is_on_vacation', false))
            ->pluck('id')
            ->all();

        return $items
            ->reject(fn (array $item) => in_array((int) $item['product_id'], $buyableProductIds, true))
            ->pluck('product_title')
            ->unique()
            ->values()
            ->all();
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

    /**
     * A product's wholesale minimum order: its lowest wholesale tier, for all options together. Null when the
     * product has no wholesale price. Every screen that states or checks the minimum uses this (UX-7).
     */
    public function wholesaleMinimumQuantity(int $productId): ?int
    {
        $moq = ProductPrice::where('product_id', $productId)
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::WHOLESALE))
            ->min('min_quantity');

        return $moq === null ? null : (int) $moq;
    }

    /**
     * Shop (retail) lines are charged the live campaign price worked out from their own tier price;
     * wholesale lines keep the tier price.
     *
     * @return array{unit_price: float, flash_sale_product_id: ?int, original_unit_price: ?float}
     */
    private function applyFlashSale(Product $product, string $channelSlug, float $tierPrice): array
    {
        if ($channelSlug !== SalesChannel::RETAIL) {
            return $this->tierOnlyPrice($tierPrice);
        }

        return app(FlashSalePricing::class)->retailLinePrice($product, $tierPrice);
    }

    /**
     * Line price with no campaign: wholesale tiers and accepted RFQ quotes.
     *
     * @return array{unit_price: float, flash_sale_product_id: null, original_unit_price: null}
     */
    private function tierOnlyPrice(float $unitPrice): array
    {
        return [
            'unit_price' => $unitPrice,
            'flash_sale_product_id' => null,
            'original_unit_price' => null,
        ];
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

    /** WA-SEC-01: wrong coupon codes allowed per user (or IP for a guest) before attempts are refused. */
    private const COUPON_ATTEMPTS = 10;

    private const COUPON_ATTEMPT_WINDOW_SECONDS = 600;

    public function applyCoupon(string $code, ?int $userId = null): array
    {
        $attemptKey = 'coupon-attempts:'.($userId ?? request()->ip());
        if (RateLimiter::tooManyAttempts($attemptKey, self::COUPON_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($attemptKey) / 60);

            return [
                'valid' => false,
                'message' => "Too many wrong coupon codes. Please try again in {$minutes} minute(s).",
                'coupon' => null,
                'discount' => 0.00,
            ];
        }

        $couponService = app(CouponService::class);
        $result = $couponService->applyCoupon($code, $this->getSubtotal(), $userId);

        if (! $result['valid']) {
            RateLimiter::hit($attemptKey, self::COUPON_ATTEMPT_WINDOW_SECONDS);
        }

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

    /**
     * The id of the coupon stored in the cart, without re-checking it. Checkout checks it under a row lock instead,
     * so a coupon that stopped being valid stops the order with a message rather than silently dropping the discount.
     */
    public function storedCouponId(): ?int
    {
        $id = Session::get(self::COUPON_KEY)['id'] ?? null;

        return $id === null ? null : (int) $id;
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

        // Re-verify against current subtotal. An invalid coupon is not shown or counted, but stays stored: Place order
        // then tells the buyer why it was dropped instead of silently charging full price (WA-CHK-01).
        $coupon = Coupon::find($couponData['id']);
        if (! $coupon || ! $coupon->is_active) {
            return null;
        }

        $validation = $coupon->validateForSubtotal($this->getSubtotal(), auth()->id());
        if (! $validation['valid']) {
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
        return Money::lineTotal($this->getItems());
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
