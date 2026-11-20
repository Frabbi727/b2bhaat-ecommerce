<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FlashSaleConflictChecker
{
    /**
     * Products of the campaign that already sit in another scheduled, active or
     * paused campaign whose window overlaps. Back-to-back windows do not overlap.
     *
     * @param  list<int>|null  $productIds  Products to check; defaults to the campaign's own.
     * @return Collection<int, array{product: string, campaign: string}>
     */
    public function conflicts(FlashSale $sale, ?array $productIds = null): Collection
    {
        return $this->conflictsForWindow($sale->id, $sale->starts_at, $sale->ends_at, $productIds ?? $sale->items()->pluck('product_id')->all());
    }

    /**
     * Same check for a proposed window, used before a date or product edit is saved.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, array{product: string, campaign: string}>
     */
    public function conflictsForWindow(?int $excludeSaleId, CarbonInterface $startsAt, CarbonInterface $endsAt, array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return FlashSaleProduct::query()
            ->with(['flashSale:id,name', 'product:id,title'])
            ->whereIn('product_id', $productIds)
            ->whereHas('flashSale', function ($query) use ($excludeSaleId, $startsAt, $endsAt): void {
                $query->whereIn('status', [
                    FlashSaleStatus::Scheduled->value,
                    FlashSaleStatus::Active->value,
                    FlashSaleStatus::Paused->value,
                ])
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt);

                if ($excludeSaleId !== null) {
                    $query->where('id', '!=', $excludeSaleId);
                }
            })
            ->get()
            ->map(fn (FlashSaleProduct $item): array => [
                'product' => (string) $item->product->title,
                'campaign' => (string) $item->flashSale->name,
            ])
            ->values();
    }
}
