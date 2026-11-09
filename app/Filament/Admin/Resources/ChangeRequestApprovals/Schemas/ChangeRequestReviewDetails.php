<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChangeRequestApprovals\Schemas;

use App\Filament\Shared\ChangeRequestSummary;
use App\Models\FlashSaleProduct;
use App\Models\ProductChangeRequest;
use Illuminate\Support\HtmlString;

/**
 * The slide-over for a held price, channel or category change: old against new, the seller's note, any campaign
 * the product sits in, and the last few approved changes. Every dynamic value goes through e().
 */
class ChangeRequestReviewDetails
{
    public static function html(ProductChangeRequest $request): HtmlString
    {
        $html = '<div class="space-y-5 text-sm">'
            .'<p class="text-base font-semibold">'.e((string) $request->product?->title).'</p>'
            .ChangeRequestSummary::body($request, 'Seller\'s note')->toHtml()
            .self::campaigns($request)
            .self::history($request);

        return new HtmlString($html.'</div>');
    }

    protected static function campaigns(ProductChangeRequest $request): string
    {
        $offers = FlashSaleProduct::query()
            ->where('product_id', $request->product_id)
            ->where('status', 'approved')
            ->whereHas('flashSale', fn ($sale) => $sale->where(fn ($q) => $q->live())->orWhere(fn ($q) => $q->upcoming()))
            ->with('flashSale')
            ->get();

        if ($offers->isEmpty()) {
            return '';
        }

        $html = '<div><p class="font-medium">Flash campaigns for this product</p><ul class="list-disc ps-5">';
        foreach ($offers as $offer) {
            $sale = $offer->flashSale;
            $html .= '<li>'.e($sale->name).' ('.($sale->isLive() ? 'live now' : 'starts '.e($sale->starts_at->timezone('Asia/Dhaka')->format('j M Y, H:i'))).')'
                .' · offer '.e($offer->campaign_price !== null
                    ? ChangeRequestSummary::money($offer->campaign_price)
                    : (string) $offer->discount_type?->value.' '.$offer->discount_value).'</li>';
        }

        return $html.'</ul></div>';
    }

    protected static function history(ProductChangeRequest $request): string
    {
        $past = ProductChangeRequest::query()
            ->where('product_id', $request->product_id)
            ->where('status', ProductChangeRequest::STATUS_APPROVED)
            ->latest('reviewed_at')
            ->limit(5)
            ->get();

        if ($past->isEmpty()) {
            return '';
        }

        $html = '<div><p class="font-medium">Last approved price changes</p><ul class="list-disc ps-5">';
        foreach ($past as $row) {
            $html .= '<li>'.e($row->reviewed_at?->timezone('Asia/Dhaka')->format('j M Y').': '.ChangeRequestSummary::heading($row).' ('.ChangeRequestSummary::largestChange($row)['text'].')').'</li>';
        }

        return $html.'</ul></div>';
    }
}
