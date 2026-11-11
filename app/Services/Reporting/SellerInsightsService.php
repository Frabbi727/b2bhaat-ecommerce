<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ProductApprovalStatus;
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
     * The ten best-selling products by revenue, with views and conversion (orders containing it ÷ views).
     *
     * @return list<array{product_id: int, title: string, units: int, revenue: float, retail_revenue: float, wholesale_revenue: float, views: int, orders: int, conversion: ?float}>
     */
    public function topProducts(int $sellerId, ?string $from, ?string $to): array
    {
        $range = $this->range($from, $to);
        $channel = $this->channelExpression();

        $sales = $this->countedLines($sellerId, $range['from'], $range['to'])
            ->join('sales_channels as sc', 'sc.id', '=', 'oi.sales_channel_id')
            ->groupBy('oi.product_id')
            ->select([
                'oi.product_id',
                DB::raw('SUM(oi.total_price) as revenue'),
                DB::raw('SUM(oi.quantity) as units'),
                DB::raw('COUNT(DISTINCT s.id) as orders'),
                DB::raw("SUM(CASE WHEN {$channel} = '".self::WHOLESALE."' THEN oi.total_price ELSE 0 END) as wholesale_revenue"),
            ]);

        return DB::query()->fromSub($sales, 't')
            ->join('products as p', 'p.id', '=', 't.product_id')
            ->leftJoinSub($this->viewsByProduct($range['from'], $range['to']), 'v', 'v.product_id', '=', 't.product_id')
            ->orderByDesc('t.revenue')
            ->orderBy('t.product_id')
            ->limit(10)
            ->get(['t.product_id', 'p.title', 't.revenue', 't.units', 't.orders', 't.wholesale_revenue', DB::raw('COALESCE(v.views, 0) as views')])
            ->map(function (object $row): array {
                $revenue = round((float) $row->revenue, 2);
                $wholesale = round((float) $row->wholesale_revenue, 2);
                $views = (int) $row->views;
                $orders = (int) $row->orders;

                return [
                    'product_id' => (int) $row->product_id,
                    'title' => (string) $row->title,
                    'units' => (int) $row->units,
                    'revenue' => $revenue,
                    'retail_revenue' => round($revenue - $wholesale, 2),
                    'wholesale_revenue' => $wholesale,
                    'views' => $views,
                    'orders' => $orders,
                    'conversion' => $this->percent((float) $orders, (float) $views),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Live products that many people opened but few bought: at least 20 views and under 1% conversion in the period.
     *
     * @return list<array{product_id: int, title: string, views: int, orders: int, conversion: float}>
     */
    public function viewedNotSelling(int $sellerId, ?string $from, ?string $to): array
    {
        $range = $this->range($from, $to);

        $orders = $this->countedLines($sellerId, $range['from'], $range['to'])
            ->groupBy('oi.product_id')
            ->select(['oi.product_id', DB::raw('COUNT(DISTINCT s.id) as orders')]);

        return $this->liveProducts($sellerId)
            ->joinSub($this->viewsByProduct($range['from'], $range['to']), 'v', 'v.product_id', '=', 'p.id')
            ->leftJoinSub($orders, 'o', 'o.product_id', '=', 'p.id')
            ->where('v.views', '>=', 20)
            ->whereRaw('COALESCE(o.orders, 0) * 100 < v.views')
            ->orderByDesc('v.views')
            ->orderBy('p.id')
            ->limit(10)
            ->get(['p.id', 'p.title', 'v.views', DB::raw('COALESCE(o.orders, 0) as orders')])
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->id,
                'title' => (string) $row->title,
                'views' => (int) $row->views,
                'orders' => (int) $row->orders,
                'conversion' => $this->percent((float) $row->orders, (float) $row->views) ?? 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Live products with no sale in the last 30 Dhaka days (whatever period is picked): never-sold first, then the
     * longest since a sale.
     *
     * @return list<array{product_id: int, title: string, last_sold_on: ?string}>
     */
    public function notSoldRecently(int $sellerId): array
    {
        $zone = (string) config('app.display_timezone');
        $storage = (string) config('app.timezone');
        $windowStart = CarbonImmutable::now($zone)->subDays(29)->startOfDay();

        $lastSales = $this->countedLines($sellerId, null, CarbonImmutable::now($zone)->endOfDay())
            ->groupBy('oi.product_id')
            ->select(['oi.product_id', DB::raw('MAX(s.created_at) as last_sold_at')]);

        return $this->liveProducts($sellerId)
            ->leftJoinSub($lastSales, 'ls', 'ls.product_id', '=', 'p.id')
            ->where(fn (Builder $query) => $query->whereNull('ls.last_sold_at')
                ->orWhere('ls.last_sold_at', '<', $windowStart->setTimezone($storage)->format('Y-m-d H:i:s')))
            ->orderByRaw('CASE WHEN ls.last_sold_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('ls.last_sold_at')
            ->orderBy('p.id')
            ->limit(10)
            ->get(['p.id', 'p.title', 'ls.last_sold_at'])
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->id,
                'title' => (string) $row->title,
                'last_sold_on' => $row->last_sold_at !== null
                    ? CarbonImmutable::parse((string) $row->last_sold_at, $storage)->setTimezone($zone)->toDateString()
                    : null,
            ])
            ->values()
            ->all();
    }

    /** The first day product views were logged (the day the log went live), or null before any view. */
    public function viewsCountedSince(): ?string
    {
        $first = DB::table('product_daily_views')->min('date');

        return $first !== null ? substr((string) $first, 0, 10) : null;
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

    /**
     * Views per product ("product_id", "views") within the Dhaka days of the period.
     */
    protected function viewsByProduct(?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('product_daily_views')
            ->when($from !== null, fn (Builder $query) => $query->where('date', '>=', $from->toDateString()))
            ->where('date', '<=', $to->toDateString())
            ->groupBy('product_id')
            ->select(['product_id', DB::raw('SUM(views) as views')]);
    }

    /**
     * The seller's products buyers can currently see ("p"): active, approved and not blocked.
     */
    protected function liveProducts(int $sellerId): Builder
    {
        return DB::table('products as p')
            ->where('p.seller_id', $sellerId)
            ->where('p.is_active', true)
            ->where('p.approval_status', ProductApprovalStatus::Approved->value)
            ->whereNull('p.blocked_at');
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
