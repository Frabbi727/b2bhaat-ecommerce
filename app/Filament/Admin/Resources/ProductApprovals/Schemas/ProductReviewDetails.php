<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductApprovals\Schemas;

use App\Filament\Shared\ChangeRequestSummary;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * The slide-over a reviewer reads before approving a new product: the product as buyers would see it, then the
 * seller's standing and any earlier "not approved" reasons. Every dynamic value goes through e().
 */
class ProductReviewDetails
{
    public static function html(Product $product): HtmlString
    {
        $product->loadMissing(['seller.sellerProfile', 'category', 'salesChannel', 'unit', 'images', 'variations', 'prices.salesChannel']);

        $html = '<div class="space-y-5 text-sm">';
        $html .= self::photos($product);
        $html .= '<div><p class="text-base font-semibold">'.e($product->title).'</p>'
            .'<p class="text-gray-500">'.e($product->category?->name ?? '—').' · '.e($product->salesChannel?->name ?? '—').'</p></div>';

        foreach (['short_description' => 'Summary', 'full_description' => 'Description'] as $column => $label) {
            if (filled($product->{$column})) {
                $html .= '<div><p class="font-medium">'.e($label).'</p><p class="whitespace-pre-line">'.e((string) $product->{$column}).'</p></div>';
            }
        }

        $html .= self::specifications($product);
        $html .= self::prices($product);
        $html .= self::variations($product);
        $html .= self::seller($product);
        $html .= self::pastRejections($product);

        return new HtmlString($html.'</div>');
    }

    protected static function photos(Product $product): string
    {
        if ($product->images->isEmpty()) {
            return '<p class="font-medium text-danger-600">No photos uploaded.</p>';
        }

        $html = '<div class="flex flex-wrap gap-2">';
        foreach ($product->images as $image) {
            $source = filter_var($image->image_url, FILTER_VALIDATE_URL) ? $image->image_url : Storage::disk('public')->url($image->image_url);
            $html .= '<img src="'.e($source).'" alt="" class="h-24 w-24 rounded-lg object-cover" loading="lazy">';
        }

        return $html.'</div>';
    }

    protected static function specifications(Product $product): string
    {
        $specifications = is_array($product->specifications) ? $product->specifications : [];
        if ($specifications === []) {
            return '';
        }

        $html = '<div><p class="font-medium">Specifications</p><ul class="list-disc ps-5">';
        foreach ($specifications as $group => $items) {
            if (is_array($items)) {
                foreach ($items as $key => $value) {
                    $html .= '<li>'.e((string) $key).': '.e(is_array($value) ? implode(', ', $value) : (string) $value).'</li>';
                }
            } else {
                $html .= '<li>'.e((string) $group).': '.e((string) $items).'</li>';
            }
        }

        return $html.'</ul></div>';
    }

    protected static function prices(Product $product): string
    {
        $html = '<div><p class="font-medium">Prices</p>';

        if ($product->regular_price !== null) {
            $html .= '<p>Compare-at price: '.e(ChangeRequestSummary::money($product->regular_price)).'</p>';
        }

        $html .= '<table class="w-full text-left"><thead><tr><th class="pe-3 font-medium">Channel</th><th class="pe-3 font-medium">From</th><th class="font-medium">Price</th></tr></thead><tbody>';
        foreach ($product->prices->sortBy([['sales_channel_id', 'asc'], ['min_quantity', 'asc']]) as $price) {
            /** @var ProductPrice $price */
            $html .= '<tr><td class="pe-3 py-1">'.e($price->salesChannel?->name ?? '—').'</td>'
                .'<td class="pe-3 py-1">'.e((string) $price->min_quantity).' '.e($product->unit?->symbol ?? 'pcs').'</td>'
                .'<td class="py-1">'.e(ChangeRequestSummary::money($price->unit_price)).'</td></tr>';
        }

        return $html.'</tbody></table></div>';
    }

    protected static function variations(Product $product): string
    {
        if (! $product->has_variations || $product->variations->isEmpty()) {
            return '';
        }

        $html = '<div><p class="font-medium">Variations</p><ul class="list-disc ps-5">';
        foreach ($product->variations as $variation) {
            $html .= '<li>'.e((string) $variation->sku).' · stock '.e((string) $variation->stock_quantity).'</li>';
        }

        return $html.'</ul></div>';
    }

    protected static function seller(Product $product): string
    {
        $profile = $product->seller?->sellerProfile;

        $state = match (true) {
            $profile === null => 'No store profile',
            $profile->is_approved => 'Store approved',
            $profile->isRejected() => 'Store not approved',
            default => 'Store waiting for approval',
        };

        return '<div><p class="font-medium">Seller</p>'
            .'<p>'.e($profile?->store_name ?? $product->seller?->name ?? '—').' · '.e($state)
            .($profile?->trade_license_number ? ' · Trade licence on file' : ' · No trade licence on file').'</p></div>';
    }

    protected static function pastRejections(Product $product): string
    {
        $rows = AuditLog::query()
            ->where('module', 'approvals')
            ->where('action', 'product_rejected')
            ->where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->latest('created_at')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $html = '<div><p class="font-medium">Earlier reasons this was not approved</p><ul class="list-disc ps-5">';
        foreach ($rows as $row) {
            $html .= '<li>'.e($row->created_at?->timezone('Asia/Dhaka')->format('d M Y').': '.(string) ($row->metadata['reason'] ?? '—')).'</li>';
        }

        return $html.'</ul></div>';
    }
}
