<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
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
            $this->assertNoConflicts($this->conflicts->conflictsForWindow(
                $locked->id,
                now(),
                $locked->ends_at,
                $locked->items()->pluck('product_id')->all(),
            ));

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
        $startsAt = $this->asDate($changes['starts_at'] ?? null) ?? $sale->starts_at;
        $endsAt = $this->asDate($changes['ends_at'] ?? null) ?? $sale->ends_at;

        if ($hasStarted && $startsAt->ne($sale->starts_at)) {
            throw new InvalidStatusTransitionException('The start time cannot change once the campaign has started.');
        }
        if (! $isDraft && $endsAt->lte(now())) {
            throw new InvalidStatusTransitionException('The end time must stay in the future. To stop the campaign now, use "Turn off now".');
        }
        if ($endsAt->lte($startsAt)) {
            throw new InvalidStatusTransitionException('The end time must be after the start time.');
        }

        $items = is_array($changes['items'] ?? null) ? $changes['items'] : [];
        $newProductIds = [];
        foreach ($items as $row) {
            if (! empty($row['flash_sale_product_id'])) {
                $this->assertStockLimitAllowed(
                    FlashSaleProduct::query()->where('flash_sale_id', $sale->id)->findOrFail($row['flash_sale_product_id']),
                    isset($row['stock_limit']) ? (int) $row['stock_limit'] : null,
                );
            } elseif (! empty($row['product_id'])) {
                $newProductIds[] = (int) $row['product_id'];
            }
        }

        $windowChanged = $startsAt->ne($sale->starts_at) || $endsAt->ne($sale->ends_at);
        if (! $isDraft && ($windowChanged || $newProductIds !== [])) {
            $productIds = array_values(array_unique([...$sale->items()->pluck('product_id')->all(), ...$newProductIds]));
            $this->assertNoConflicts($this->conflicts->conflictsForWindow($sale->id, $startsAt, $endsAt, $productIds));
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

    private function asDate(mixed $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value);
    }
}
