<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlashSaleLifecycleService
{
    public function __construct(private readonly FlashSaleConflictChecker $conflicts) {}

    public function publish(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            if ($locked->status !== FlashSaleStatus::Draft) {
                throw new InvalidStatusTransitionException('Only a draft can be published.');
            }
            if (! $locked->items()->exists()) {
                throw new InvalidStatusTransitionException('Add at least one product before publishing.');
            }
            if ($locked->ends_at->lte(now())) {
                throw new InvalidStatusTransitionException('The end time has already passed.');
            }
            if ($locked->ends_at->lte($locked->starts_at)) {
                throw new InvalidStatusTransitionException('The end time must be after the start time.');
            }
            $this->lockProducts($locked->items()->pluck('product_id')->all());
            $this->assertNoConflicts($this->conflicts->conflicts($locked));

            $locked->update(['status' => FlashSaleStatus::Scheduled]);
        });
    }

    public function startNow(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            if ($locked->status !== FlashSaleStatus::Scheduled || $locked->starts_at->lte(now())) {
                throw new InvalidStatusTransitionException('This campaign has already started or cannot be started now.');
            }
            $productIds = $locked->items()->pluck('product_id')->all();
            $this->lockProducts($productIds);
            $this->assertNoConflicts($this->conflicts->conflictsForWindow($locked->id, now(), $locked->ends_at, $productIds));

            $locked->update(['starts_at' => now()]);
        });
    }

    public function pause(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            if (! in_array($locked->effectiveStatus(), [FlashSaleStatus::Scheduled, FlashSaleStatus::Active], true)) {
                throw new InvalidStatusTransitionException('Only a scheduled or running campaign can be paused.');
            }

            $locked->update(['status' => FlashSaleStatus::Paused, 'paused_at' => now()]);
        });
    }

    public function resume(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            if ($locked->status !== FlashSaleStatus::Paused) {
                throw new InvalidStatusTransitionException('Only a paused campaign can be resumed.');
            }
            if ($locked->ends_at->lte(now())) {
                throw new InvalidStatusTransitionException('This campaign has ended, so it cannot be resumed. Duplicate it to run it again.');
            }
            $this->lockProducts($locked->items()->pluck('product_id')->all());
            $this->assertNoConflicts($this->conflicts->conflicts($locked));

            $locked->update(['status' => FlashSaleStatus::Scheduled, 'paused_at' => null]);
        });
    }

    public function endNow(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            if (! in_array($locked->effectiveStatus(), [FlashSaleStatus::Active, FlashSaleStatus::Paused], true)) {
                throw new InvalidStatusTransitionException('Only a running or paused campaign can be turned off. Cancel it if it has not started.');
            }

            $locked->update(['status' => FlashSaleStatus::Ended, 'ended_at' => now()]);
        });
    }

    public function cancel(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, function (FlashSale $locked): void {
            $effective = $locked->effectiveStatus();
            $beforeStart = $effective === FlashSaleStatus::Scheduled;
            $pausedUnsold = $effective === FlashSaleStatus::Paused && $this->unitsSold($locked) === 0;

            if ($effective !== FlashSaleStatus::Draft && ! $beforeStart && ! $pausedUnsold) {
                throw new InvalidStatusTransitionException('This campaign has already run or has sales, so it cannot be cancelled. Use "Turn off now" instead.');
            }

            $locked->update(['status' => FlashSaleStatus::Cancelled]);
        });
    }

    public function duplicate(FlashSale $sale): FlashSale
    {
        return DB::transaction(function () use ($sale): FlashSale {
            $copy = FlashSale::create([
                'name' => $sale->name.' (copy)',
                'slug' => Str::slug($sale->name).'-copy-'.Str::lower(Str::random(6)),
                'description' => $sale->description,
                'banner_path' => $sale->banner_path,
                'terms' => $sale->terms,
                'status' => FlashSaleStatus::Draft,
                'starts_at' => $sale->starts_at,
                'ends_at' => $sale->ends_at,
                'is_featured' => $sale->is_featured,
                'display_priority' => $sale->display_priority,
                'created_by' => $sale->created_by,
            ]);

            foreach ($sale->items as $item) {
                $copy->items()->create($item->only([
                    'product_id', 'discount_type', 'discount_value', 'stock_limit', 'per_customer_limit', 'sort_order',
                ]));
            }

            return $copy->fresh();
        });
    }

    public function delete(FlashSale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $locked = FlashSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== FlashSaleStatus::Draft) {
                throw new InvalidStatusTransitionException('Only drafts can be deleted. Cancel or turn off the campaign instead.');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * Checks a form submission against the editing rules before it is saved.
     *
     * @param  array<string, mixed>  $changes  Attributes as the form submits them; `items` may hold rows with
     *                                         `flash_sale_product_id` (existing), `product_id` (new) and `stock_limit`.
     */
    public function assertEditable(FlashSale $sale, array $changes): void
    {
        $effective = $sale->effectiveStatus();

        if (in_array($effective, [FlashSaleStatus::Ended, FlashSaleStatus::Cancelled], true)) {
            throw new InvalidStatusTransitionException('This campaign is '.strtolower($effective->label()).' and cannot be edited. Duplicate it to run it again.');
        }

        $isDraft = $sale->status === FlashSaleStatus::Draft;
        $hasStarted = ! $isDraft && $sale->starts_at->lte(now());
        $hasStartsAtChange = array_key_exists('starts_at', $changes) && $changes['starts_at'] !== null && $changes['starts_at'] !== '';
        $hasEndsAtChange = array_key_exists('ends_at', $changes) && $changes['ends_at'] !== null && $changes['ends_at'] !== '';

        $startsAt = $hasStartsAtChange ? ($this->asDate($changes['starts_at']) ?? $sale->starts_at) : $sale->starts_at;
        $endsAt = $hasEndsAtChange ? ($this->asDate($changes['ends_at']) ?? $sale->ends_at) : $sale->ends_at;

        if ($hasStarted && $hasStartsAtChange) {
            $isSame = $startsAt->equalTo($sale->starts_at);
            if (! $isSame && is_string($changes['starts_at'])) {
                $startsAtDhaka = $this->asDate($changes['starts_at'], 'Asia/Dhaka');
                $isSame = $startsAtDhaka !== null && $startsAtDhaka->equalTo($sale->starts_at);
            }

            if (! $isSame) {
                throw new InvalidStatusTransitionException('The start time cannot change once the campaign has started.');
            }
        }
        if (! $isDraft && $hasEndsAtChange && $endsAt->lte(now())) {
            throw new InvalidStatusTransitionException('The end time must stay in the future. To stop the campaign now, use "Turn off now".');
        }
        if ($endsAt->lte($startsAt)) {
            throw new InvalidStatusTransitionException('The end time must be after the start time.');
        }

        $hasItems = is_array($changes['items'] ?? null);
        $items = $hasItems ? $changes['items'] : [];
        $newProductIds = [];
        $keptItemIds = [];
        foreach ($items as $row) {
            if (! empty($row['flash_sale_product_id'])) {
                $keptItemIds[] = (int) $row['flash_sale_product_id'];
                $stockLimit = $row['stock_limit'] ?? null;
                $this->assertStockLimitAllowed(
                    FlashSaleProduct::query()->where('flash_sale_id', $sale->id)->findOrFail($row['flash_sale_product_id']),
                    $stockLimit === null || $stockLimit === '' ? null : (int) $stockLimit,
                );
            } elseif (! empty($row['product_id'])) {
                $newProductIds[] = (int) $row['product_id'];
            }
        }

        if ($hasItems) {
            $sale->items()->whereNotIn('id', $keptItemIds)->get()->each(fn (FlashSaleProduct $item) => $this->assertItemRemovable($item));
        }

        $windowChanged = $startsAt->ne($sale->starts_at) || $endsAt->ne($sale->ends_at);
        if (! $isDraft && ($windowChanged || $newProductIds !== [])) {
            $productIds = array_values(array_unique([...$sale->items()->pluck('product_id')->all(), ...$newProductIds]));
            DB::transaction(function () use ($sale, $startsAt, $endsAt, $productIds): void {
                $this->lockProducts($productIds);
                $this->assertNoConflicts($this->conflicts->conflictsForWindow($sale->id, $startsAt, $endsAt, $productIds));
            });
        }
    }

    /**
     * A product that has sales stays in its campaign; lower its stock limit instead.
     */
    public function assertItemRemovable(FlashSaleProduct $item): void
    {
        if ($item->unitsSold() > 0) {
            throw new InvalidStatusTransitionException('This product already has sales in the campaign, so it cannot be removed. Lower its stock limit instead.');
        }
    }

    /**
     * Serialises overlap checks across campaigns: two campaigns sharing a product lock the same product rows, so only one checks at a time.
     *
     * @param  list<int>  $productIds
     */
    private function lockProducts(array $productIds): void
    {
        if ($productIds !== []) {
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * A product's stock limit may be raised, lowered or cleared, but never set below what is already sold.
     */
    public function assertStockLimitAllowed(FlashSaleProduct $item, ?int $newLimit): void
    {
        if ($newLimit === null) {
            return;
        }

        $sold = $item->unitsSold();
        if ($newLimit < $sold) {
            throw new InvalidStatusTransitionException("The stock limit cannot be lower than the {$sold} units already sold.");
        }
    }

    /**
     * @param  callable(FlashSale): void  $change
     */
    private function transition(FlashSale $sale, callable $change): FlashSale
    {
        return DB::transaction(function () use ($sale, $change): FlashSale {
            $locked = FlashSale::query()->lockForUpdate()->findOrFail($sale->id);
            $change($locked);

            return $locked->fresh();
        });
    }

    /**
     * @param  Collection<int, array{product: string, campaign: string}>  $conflicts
     */
    private function assertNoConflicts(Collection $conflicts): void
    {
        if ($conflicts->isEmpty()) {
            return;
        }

        $list = $conflicts->map(fn (array $c): string => "\"{$c['product']}\" is already in \"{$c['campaign']}\"")->implode('; ');

        throw new InvalidStatusTransitionException("A product cannot be in two campaigns at the same time: {$list}.");
    }

    private function unitsSold(FlashSale $sale): int
    {
        return $sale->items->sum(fn (FlashSaleProduct $item): int => $item->unitsSold());
    }

    private function asDate(mixed $value, ?string $timezone = null): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
