<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\FulfillmentStatus;
use Filament\Widgets\ChartWidget;

class AdminFulfillmentDistributionChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Packages by status';

    protected ?string $description = 'Where every seller package is right now.';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $statuses = FulfillmentStatus::withCount('subOrders')->get();

        $labels = [];
        $data = [];
        $colors = [];

        $colorMap = [
            'delivered' => '#10b981',
            'shipped' => '#3b82f6',
            'in_transit' => '#6366f1',
            'processing' => '#f59e0b',
            'unfulfilled' => '#94a3b8',
            'returned' => '#ef4444',
            'cancelled' => '#64748b',
        ];

        foreach ($statuses as $status) {
            $labels[] = $status->name;
            $data[] = $status->sub_orders_count;
            $colors[] = $colorMap[$status->slug] ?? '#cbd5e1';
        }

        return [
            'datasets' => [
                [
                    'label' => 'Packages',
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
