<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies a seller's product as a hidden draft so a similar item can be listed without retyping it.
 * The copy keeps catalogue content, pricing tiers, photos and variation options, but starts with zero stock,
 * no promotions, no sales history and no moderation state.
 */
class ProductDuplicationService
{
    public function duplicate(Product $source): Product
    {
        return DB::transaction(function () use ($source): Product {
            // Re-read the product so table aggregates (e.g. variations_sum_stock_quantity) are not copied as columns
            $source = Product::with(['images', 'prices', 'variations.attributeValues'])->findOrFail($source->getKey());

            $copy = $source->replicate([
                'views_count', 'sales_count', 'blocked_at', 'blocked_reason',
                'approval_status', 'submitted_at', 'approved_at', 'approved_by_user_id',
                'rejected_at', 'rejection_reason', 'reviewed_by_user_id',
            ]);
            $copy->forceFill([
                'title' => Str::limit($source->title, 240, '').' (copy)',
                'slug' => $this->uniqueSlug($source->slug),
                // A fresh SKU and barcode are generated for the copy (Product::booted)
                'sku' => null,
                'barcode' => null,
                'buy_price' => $source->getAttribute('buy_price') ?? 0,
                'is_active' => false,
                'is_featured' => false,
                'views_count' => 0,
                'sales_count' => 0,
            ]);
            // Simple products get their default zero-stock variation from Product::booted()
            $copy->save();

            $variationMap = [];
            if ($source->has_variations) {
                foreach ($source->variations as $variation) {
                    $newVariation = $copy->variations()->create([
                        'buy_price' => $variation->getAttribute('buy_price') ?? 0,
                        'stock_quantity' => 0,
                        'is_active' => $variation->is_active,
                    ]);
                    $newVariation->attributeValues()->sync($variation->attributeValues->modelKeys());
                    $variationMap[$variation->id] = $newVariation->id;
                }
            }

            foreach ($source->prices as $price) {
                if ($price->variation_id !== null && ! isset($variationMap[$price->variation_id])) {
                    continue;
                }

                $copy->prices()->create([
                    'variation_id' => $price->variation_id !== null ? $variationMap[$price->variation_id] : null,
                    'sales_channel_id' => $price->sales_channel_id,
                    'min_quantity' => $price->min_quantity,
                    'max_quantity' => $price->max_quantity,
                    'unit_price' => $price->unit_price,
                ]);
            }

            foreach ($source->images as $image) {
                $copy->images()->create([
                    'image_url' => $image->image_url,
                    'is_primary' => $image->is_primary,
                    'sort_order' => $image->sort_order,
                ]);
            }

            return $copy->refresh();
        });
    }

    protected function uniqueSlug(string $slug): string
    {
        $base = Str::limit($slug, 240, '').'-copy';
        $candidate = $base;
        $counter = 2;

        while (Product::where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
