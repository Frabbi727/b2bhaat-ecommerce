<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Seller sales insights: what sold, who bought, and how each period compares with the one before.
 *
 * Every figure is the given seller's own. An order counts when its sub-order is not cancelled, on the Dhaka day the
 * sub-order was created; revenue is the order-line total before returns. All aggregation happens in SQL so the page
 * stays light on a small host.
 */
class SellerInsightsService
{
    private const string WHOLESALE = 'wholesale';

    /**
     * Revenue, orders, average order value and units, each with the change against the previous period.
     *
     * @return array{revenue: array{value: float, change: ?float}, orders: array{value: int, change: ?float}, average_order_value: array{value: float, change: ?float}, units: array{value: int, change: ?float}}
     */
    public function summary(int $sellerId, ?string $from, ?string $to): array
    {
        $range = $this->range($from, $to);
        $current = $this->totals($sellerId, $range['from'], $range['to']);
        $previous = $range['previous'] !== null ? $this->totals($sellerId, $range['previous']['from'], $range['previous']['to']) : null;

        $figure = fn (string $key): array => [
            'value' => $current[$key],
            'change' => $previous !== null ? $this->change((float) $current[$key], (float) $previous[$key]) : null,
        ];

        return [
            'revenue' => $figure('revenue'),
            'orders' => $figure('orders'),
            'average_order_value' => $figure('average_order_value'),
            'units' => $figure('units'),
        ];
    }

    /**
     * Revenue and orders per channel. Shares are by revenue so they add up to 100; a sub-order holding both retail
     * and wholesale lines counts in each channel's orders and in mixed_orders.
     *
     * @return array{retail: array{revenue: float, orders: int, share: float}, wholesale: array{revenue: float, orders: int, share: float}, mixed_orders: int}
     */
    public function channelSplit(int $sellerId, ?string $from, ?string $to): array
    {
        $range = $this->range($from, $to);
        $channel = $this->channelExpression();

        $rows = $this->countedLines($sellerId, $range['from'], $range['to'])
            ->join('sales_channels as sc', 'sc.id', '=', 'oi.sales_channel_id')
            ->groupBy(DB::raw($channel))
            ->get([DB::raw("{$channel} as channel"), DB::raw('SUM(oi.total_price) as revenue'), DB::raw('COUNT(DISTINCT s.id) as orders')])
            ->keyBy('channel');

        $mixedOrders = DB::query()->fromSub(
            $this->countedLines($sellerId, $range['from'], $range['to'])
                ->join('sales_channels as sc', 'sc.id', '=', 'oi.sales_channel_id')
                ->groupBy('s.id')
                ->havingRaw("COUNT(DISTINCT {$channel}) > 1")
                ->select('s.id'),
            'mixed'
        )->count();

        $retailRevenue = round((float) ($rows['retail']->revenue ?? 0), 2);
        $wholesaleRevenue = round((float) ($rows[self::WHOLESALE]->revenue ?? 0), 2);
        $total = $retailRevenue + $wholesaleRevenue;

        return [
            'retail' => ['revenue' => $retailRevenue, 'orders' => (int) ($rows['retail']->orders ?? 0), 'share' => $this->percent($retailRevenue, $total) ?? 0.0],
            self::WHOLESALE => ['revenue' => $wholesaleRevenue, 'orders' => (int) ($rows[self::WHOLESALE]->orders ?? 0), 'share' => $this->percent($wholesaleRevenue, $total) ?? 0.0],
            'mixed_orders' => $mixedOrders,
        ];
    }

    /**
     * The period as Dhaka day bounds. The end is capped at today; without a start there is no previous period.
     * The previous period is the same number of days, ending the day before the start.
     *
     * @return array{from: ?CarbonImmutable, to: CarbonImmutable, previous: ?array{from: CarbonImmutable, to: CarbonImmutable}}
     */
    protected function range(?string $from, ?string $to): array
    {
        $zone = (string) config('app.display_timezone');
        $today = CarbonImmutable::now($zone)->endOfDay();

        $end = $to !== null ? CarbonImmutable::parse($to, $zone)->endOfDay() : $today;
        $end = $end->greaterThan($today) ? $today : $end;
        $start = $from !== null ? CarbonImmutable::parse($from, $zone)->startOfDay() : null;

        $previous = null;
        if ($start !== null && $start->lessThanOrEqualTo($end)) {
            $days = (int) $start->diffInDays($end->startOfDay()) + 1;
            $previous = [
                'from' => $start->subDays($days),
                'to' => $start->subDay()->endOfDay(),
            ];
        }

        return ['from' => $start, 'to' => $end, 'previous' => $previous];
    }

    /**
     * The seller's non-cancelled sub-orders created within the Dhaka day bounds, aliased "s".
     */
    protected function countedSubOrders(int $sellerId, ?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $storage = (string) config('app.timezone');

        return DB::table('sub_orders as s')
            ->join('fulfillment_statuses as fs', 'fs.id', '=', 's.fulfillment_status_id')
            ->where('s.seller_id', $sellerId)
            ->where('fs.slug', '!=', 'cancelled')
            ->when($from !== null, fn (Builder $query) => $query->where('s.created_at', '>=', $from->setTimezone($storage)->format('Y-m-d H:i:s')))
            ->where('s.created_at', '<=', $to->setTimezone($storage)->format('Y-m-d H:i:s'));
    }

    /**
     * Order lines ("oi") of the counted sub-orders.
     */
    protected function countedLines(int $sellerId, ?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->countedSubOrders($sellerId, $from, $to)->join('order_items as oi', 'oi.sub_order_id', '=', 's.id');
    }

    /** SQL that reads an order line's channel as "wholesale" or "retail" (needs the "sc" join). */
    protected function channelExpression(): string
    {
        return "CASE WHEN sc.slug = '".self::WHOLESALE."' THEN '".self::WHOLESALE."' ELSE 'retail' END";
    }

    /**
     * @return array{revenue: float, orders: int, average_order_value: float, units: int}
     */
    private function totals(int $sellerId, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->countedLines($sellerId, $from, $to)
            ->first([DB::raw('COALESCE(SUM(oi.total_price), 0) as revenue'), DB::raw('COUNT(DISTINCT s.id) as orders'), DB::raw('COALESCE(SUM(oi.quantity), 0) as units')]);

        $revenue = round((float) ($row->revenue ?? 0), 2);
        $orders = (int) ($row->orders ?? 0);

        return [
            'revenue' => $revenue,
            'orders' => $orders,
            'average_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'units' => (int) ($row->units ?? 0),
        ];
    }

    /** Percent change from the previous value, one decimal; null when there is nothing to compare with. */
    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    /** part ÷ whole as a percentage, one decimal; null when the whole is zero. */
    protected function percent(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
