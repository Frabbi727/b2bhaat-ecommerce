<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Models\SubOrder;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class SellerRevenueChart extends ChartWidget
{
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
        $days = collect(range(13, 0))->map(fn ($i) => now()->subDays($i)->format('Y-m-d'));

        $subOrders = SubOrder::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->whereDoesntHave('fulfillmentStatus', fn ($q) => $q->where('slug', 'cancelled'))
            ->get(['id', 'subtotal', 'created_at']);

        $dailyEarnings = [];
        $labels = [];

        foreach ($days as $dateStr) {
            $carbon = Carbon::parse($dateStr);
            $labels[] = $carbon->format('d M');

            $daySubOrders = $subOrders->filter(fn ($s) => $s->created_at->format('Y-m-d') === $dateStr);
            $dailyEarnings[] = round((float) $daySubOrders->sum('subtotal'), 2);
        }

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
