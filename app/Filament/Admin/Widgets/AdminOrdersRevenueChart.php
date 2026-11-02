<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class AdminOrdersRevenueChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Marketplace Revenue & GMV Trajectory';

    protected ?string $description = 'Daily paid transaction volume over the last 14 days.';

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
        $days = collect(range(13, 0))->map(fn ($i) => now()->subDays($i)->format('Y-m-d'));

        $orders = Order::whereHas('paymentStatus', fn ($q) => $q->where('slug', 'paid'))
            ->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->get();

        $dailyGmv = [];
        $labels = [];

        foreach ($days as $dateStr) {
            $carbon = Carbon::parse($dateStr);
            $labels[] = $carbon->format('d M');

            $dayOrders = $orders->filter(fn ($o) => $o->created_at->format('Y-m-d') === $dateStr);
            $dailyGmv[] = round((float) $dayOrders->sum('total_amount'), 2);
        }

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
