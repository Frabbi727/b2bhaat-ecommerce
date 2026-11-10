<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Models\SubOrder;
use App\Services\Reporting\DailyTotals;
use Filament\Widgets\ChartWidget;

class SellerRevenueChart extends ChartWidget
{
    /** PERF-2: refresh once a minute rather than Filament's default of every 5 seconds. */
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Sales, last 14 days';

    protected ?string $description = 'Order value received each day (৳), excluding cancelled orders.';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $sellerId = auth()->id();
        $series = app(DailyTotals::class)->lastDays(
            SubOrder::query()
                ->where('seller_id', $sellerId)
                ->whereDoesntHave('fulfillmentStatus', fn ($q) => $q->where('slug', 'cancelled')),
            'subtotal',
            14,
        );
        $labels = $series['labels'];
        $dailyEarnings = $series['values'];

        return [
            'datasets' => [
                [
                    'label' => 'Sales (৳)',
                    'data' => $dailyEarnings,
                    'borderColor' => '#059669',
                    'backgroundColor' => 'rgba(5, 150, 105, 0.08)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
