<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages;

use App\Filament\Seller\Pages\Accounts\Concerns\HasReportPeriod;
use App\Filament\Seller\Pages\Accounts\Concerns\InteractsWithSellerBooks;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Services\Reporting\SellerInsightsService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Which products and buyers drive the seller's sales: summary, top products with conversion, products that need
 * attention, repeat buyers and the retail/wholesale split, for a chosen period.
 */
class SalesInsights extends Page
{
    use HasReportPeriod;
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Sales insights';

    protected static ?string $title = 'Sales Insights';

    protected static ?string $slug = 'sales-insights';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected string $view = 'filament.seller.pages.sales-insights';

    protected function defaultPreset(): string
    {
        return '30d';
    }

    /**
     * @return array<string, string>
     */
    public function periodPresets(): array
    {
        return [
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            'month' => 'This month',
            'last_month' => 'Last month',
            'year' => 'This year',
            'all' => 'All time',
        ];
    }

    /**
     * @return array{revenue: array{value: float, change: ?float}, orders: array{value: int, change: ?float}, average_order_value: array{value: float, change: ?float}, units: array{value: int, change: ?float}}
     */
    public function getSummary(): array
    {
        return $this->insights()->summary($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * @return list<array{product_id: int, title: string, units: int, revenue: float, retail_revenue: float, wholesale_revenue: float, views: int, orders: int, conversion: ?float}>
     */
    public function getTopProducts(): array
    {
        return $this->insights()->topProducts($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * @return list<array{product_id: int, title: string, views: int, orders: int, conversion: float}>
     */
    public function getViewedNotSelling(): array
    {
        return $this->insights()->viewedNotSelling($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * @return list<array{product_id: int, title: string, last_sold_on: ?string}>
     */
    public function getNotSoldRecently(): array
    {
        return $this->insights()->notSoldRecently($this->sellerId());
    }

    /**
     * @return array{unique: int, repeat: int, repeat_rate: ?float}
     */
    public function getBuyerSummary(): array
    {
        return $this->insights()->buyerSummary($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * @return list<array{customer_id: int, name: string, type: string, orders: int, spend: float, last_order_on: string}>
     */
    public function getBestCustomers(): array
    {
        return $this->insights()->bestCustomers($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * @return array{retail: array{revenue: float, orders: int, share: float}, wholesale: array{revenue: float, orders: int, share: float}, mixed_orders: int}
     */
    public function getChannelSplit(): array
    {
        return $this->insights()->channelSplit($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    /**
     * "Views counted from …" when the period reaches back before the view log existed, otherwise null.
     */
    public function viewsNote(): ?string
    {
        $since = $this->insights()->viewsCountedSince();
        $from = $this->periodFrom();

        if ($since !== null && $from !== null && $from >= $since) {
            return null;
        }

        $day = $since ?? now(config('app.display_timezone'))->toDateString();

        return 'Views counted from '.CarbonImmutable::parse($day)->format('d M Y').'.';
    }

    public function productUrl(int $productId): string
    {
        return ProductResource::getUrl('edit', ['record' => $productId]);
    }

    /** "12 Nov 2026" from a Y-m-d day. */
    public function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d M Y');
    }

    private function insights(): SellerInsightsService
    {
        return app(SellerInsightsService::class);
    }
}
