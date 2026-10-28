<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\SalesChannel;
use Exception;
use Illuminate\Support\Facades\Session;

class CartSessionService
{
    protected const SESSION_KEY = 'b2bhaat_cart_items';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getItems(): array
    {
        return Session::get(self::SESSION_KEY, []);
    }

    /**
     * Add item with role and MOQ validation.
     *
     * @throws Exception
     */
    public function addItem(int $productId, ?int $variationId, string $channelSlug, int $quantity): void
    {
        $product = Product::with(['prices.salesChannel', 'variations.attributeValues.attribute', 'seller.sellerProfile'])
            ->findOrFail($productId);

        $variation = $variationId ? $product->variations->firstWhere('id', $variationId) : null;

        // Fetch channel-specific price
        $priceRecord = $product->prices
            ->where('salesChannel.slug', $channelSlug)
            ->where('variation_id', $variationId)
            ->first()
            ?? $product->prices
                ->where('salesChannel.slug', $channelSlug)
                ->whereNull('variation_id')
                ->first();

        if (! $priceRecord) {
            throw new Exception("Pricing for {$channelSlug} channel is unavailable for this item.");
        }

        $minQty = $priceRecord->min_quantity ?? 1;

        if ($channelSlug === SalesChannel::WHOLESALE && $quantity < $minQty) {
            throw new Exception("Wholesale minimum order quantity is {$minQty}. You requested {$quantity}.");
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
        } else {
            $items[$key] = [
                'key' => $key,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'product_slug' => $product->slug,
                'variation_id' => $variationId,
                'variation_sku' => $sku,
                'attributes_summary' => $attrs,
                'sales_channel_id' => $priceRecord->sales_channel_id,
                'sales_channel_slug' => $channelSlug,
                'unit_price' => (float) $priceRecord->unit_price,
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
