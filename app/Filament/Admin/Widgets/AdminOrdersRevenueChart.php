<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\Order;
use App\Services\Reporting\DailyTotals;
use Filament\Widgets\ChartWidget;

class AdminOrdersRevenueChart extends ChartWidget
{
    /** PERF-2: refresh once a minute rather than Filament's default of every 5 seconds. */
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Paid order value, last 14 days';

    protected ?string $description = 'Daily value of paid orders (৳).';

    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(DailyTotals::class)->lastDays(
            Order::query()->whereHas('paymentStatus', fn ($q) => $q->where('slug', 'paid')),
            'total_amount',
            14,
        );
        $labels = $series['labels'];
        $dailyGmv = $series['values'];

        return [
            'datasets' => [
                [
                    'label' => 'Settled GMV (BDT)',
                    'data' => $dailyGmv,
                    'borderColor' => '#4f46e5',
                    'backgroundColor' => 'rgba(79, 70, 229, 0.08)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
