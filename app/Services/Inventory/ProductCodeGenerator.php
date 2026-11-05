<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Str;

/**
 * Generates the identifiers sellers no longer type by hand.
 *
 * - Product SKU: `{CAT}-{id:6}`, e.g. `BEA-000042`; unique because the product id is.
 * - Variation SKU: `{product sku}-{nn}`, e.g. `BEA-000042-01`.
 * - Barcode: EAN-13 in the GS1 restricted-circulation range (prefix 20), so it scans anywhere but can never
 *   collide with a manufacturer's registered barcode: `20` + 10-digit product id + check digit.
 */
class ProductCodeGenerator
{
    public const BARCODE_PREFIX = '20';

    public static function productSku(Product $product): string
    {
        $categorySlug = $product->category?->slug ?? $product->category()->value('slug') ?? '';
        $letters = Str::upper(Str::substr((string) preg_replace('/[^A-Za-z]/', '', $categorySlug), 0, 3));
        $prefix = strlen($letters) === 3 ? $letters : 'PRD';

        return $prefix.'-'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT);
    }

    public static function variationSku(Product $product): string
    {
        $base = $product->sku ?: self::productSku($product);
        $sequence = $product->variations()->count() + 1;

        do {
            $candidate = $base.'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            $sequence++;
        } while (ProductVariation::where('sku', $candidate)->exists());

        return $candidate;
    }

    public static function barcode(int $productId): string
    {
        $body = self::BARCODE_PREFIX.str_pad((string) $productId, 10, '0', STR_PAD_LEFT);

        return $body.self::ean13CheckDigit($body);
    }

    public static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
