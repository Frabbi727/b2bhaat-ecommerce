<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PERF-2: daily totals for dashboard charts. Reads only the amount and timestamp columns of the rows in range and
 * counts each row on its Dhaka calendar day (rows are stored in the app timezone, UTC).
 */
class DailyTotals
{
    /**
     * @param  Builder<Model>  $query
     * @return array{labels: list<string>, values: list<float>}
     */
    public function lastDays(Builder $query, string $amountColumn, int $days, string $labelFormat = 'd M'): array
    {
        $displayZone = (string) config('app.display_timezone');
        $storageZone = (string) config('app.timezone');
        $today = Carbon::now($displayZone)->startOfDay();
        $firstDay = $today->copy()->subDays($days - 1);

        $totals = [];
        $table = $query->getModel()->getTable();

        $rows = $query
            ->where("{$table}.created_at", '>=', $firstDay->copy()->setTimezone($storageZone))
            ->toBase()
            ->get(["{$table}.{$amountColumn} as amount", "{$table}.created_at as created_at"]);

        foreach ($rows as $row) {
            $day = Carbon::parse((string) $row->created_at, $storageZone)->setTimezone($displayZone)->format('Y-m-d');
            $totals[$day] = ($totals[$day] ?? 0) + (int) round(((float) $row->amount) * 100);
        }

        $labels = [];
        $values = [];

        for ($day = $firstDay->copy(); $day->lte($today); $day->addDay()) {
            $labels[] = $day->format($labelFormat);
            $values[] = round(($totals[$day->format('Y-m-d')] ?? 0) / 100, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
