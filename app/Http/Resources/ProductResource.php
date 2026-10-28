<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array with server-side channel masking.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isWholesaleUser = $user && $user->isWholesaleBuyer();
        $channelSlug = $this->salesChannel?->slug ?? SalesChannel::BOTH;

        // Fetch retail and wholesale prices
        $retailPriceRecord = $this->prices
            ->first(fn (ProductPrice $p) => $p->salesChannel?->slug === SalesChannel::RETAIL && is_null($p->variation_id));

        $wholesalePriceRecord = $this->prices
            ->first(fn (ProductPrice $p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && is_null($p->variation_id));

        $pricing = [];
        $wholesaleTeaser = null;

        if ($isWholesaleUser) {
            $pricing = [
                'wholesale_price' => $wholesalePriceRecord ? (float) $wholesalePriceRecord->unit_price : null,
                'min_order_quantity' => $wholesalePriceRecord ? $wholesalePriceRecord->min_quantity : 1,
                'retail_price' => $retailPriceRecord ? (float) $retailPriceRecord->unit_price : null,
            ];
        } else {
            // Retail buyer or unauthenticated guest: strictly mask wholesale pricing
            $pricing = [
                'retail_price' => $retailPriceRecord ? (float) $retailPriceRecord->unit_price : null,
                'min_order_quantity' => $retailPriceRecord ? $retailPriceRecord->min_quantity : 1,
            ];

            if (in_array($channelSlug, [SalesChannel::WHOLESALE, SalesChannel::BOTH])) {
                $wholesaleTeaser = [
                    'available' => true,
                    'message' => 'Wholesale bulk pricing available for verified businesses. Login to unlock.',
                ];
            }
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'channel' => $channelSlug,
            'category' => $this->category?->name,
            'brand' => $this->brand?->name,
            'unit' => $this->unit?->symbol,
            'short_description' => $this->short_description,
            'full_description' => $this->full_description,
            'pricing' => $pricing,
            'wholesale_teaser' => $wholesaleTeaser,
            'images' => $this->images->map(fn ($img) => [
                'url' => $img->image_url,
                'is_primary' => $img->is_primary,
            ]),
            'variations' => $this->variations->map(fn ($variation) => [
                'id' => $variation->id,
                'sku' => $variation->sku,
                'stock_available' => $isWholesaleUser ? $variation->stock_quantity : null,
                'in_stock' => $variation->stock_quantity > 0,
                'attributes' => $variation->attributeValues->pluck('value', 'attribute.name'),
            ]),
        ];
    }
}
