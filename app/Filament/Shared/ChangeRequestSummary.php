<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Models\Category;
use App\Models\ProductChangeRequest;
use App\Models\SalesChannel;
use Illuminate\Support\HtmlString;

/**
 * Plain-language summary of a held price, channel or category change: what it changes and an old-against-new table.
 */
class ChangeRequestSummary
{
    /**
     * "new prices", "new category", or both joined by " and ".
     */
    public static function heading(ProductChangeRequest $request): string
    {
        $parts = [];

        if (self::changesPrices($request)) {
            $parts[] = 'new prices';
        }

        if ($request->proposed_category_id !== null) {
            $parts[] = 'new category';
        }

        return $parts === [] ? 'new prices' : implode(' and ', $parts);
    }

    public static function changesPrices(ProductChangeRequest $request): bool
    {
        return $request->proposed_sales_channel_id !== null
            || self::moneyOrNull($request->proposed_regular_price) !== self::moneyOrNull($request->base_regular_price)
            || self::priceDifferences($request) !== [];
    }

    public static function money(int|float|string $value): string
    {
        return '৳'.number_format((float) $value, 2);
    }

    /**
     * The callout body: the channel and category lines, then the tier and compare-at table.
     */
    public static function body(ProductChangeRequest $request, string $noteLabel = 'Your note'): HtmlString
    {
        $html = '<div class="space-y-3 text-sm">';

        foreach (self::lines($request) as $line) {
            $html .= '<p class="font-medium">'.e($line).'</p>';
        }

        $rows = self::priceDifferences($request);
        $regular = [self::moneyOrNull($request->base_regular_price), self::moneyOrNull($request->proposed_regular_price)];

        if ($rows !== [] || $regular[0] !== $regular[1]) {
            $html .= '<table class="w-full text-left"><thead><tr>'
                .'<th class="pe-3 font-medium">Price</th><th class="pe-3 font-medium">Current</th><th class="font-medium">New</th>'
                .'</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr><td class="pe-3 py-1">'.e($row['label']).'</td>'
                    .'<td class="pe-3 py-1">'.e($row['old'] === null ? 'new' : self::money($row['old'])).'</td>'
                    .'<td class="py-1">'.e($row['new'] === null ? 'removed' : self::money($row['new'])).'</td></tr>';
            }

            if ($regular[0] !== $regular[1]) {
                $html .= '<tr><td class="pe-3 py-1">Compare-at price</td>'
                    .'<td class="pe-3 py-1">'.e($regular[0] === null ? 'none' : self::money($regular[0])).'</td>'
                    .'<td class="py-1">'.e($regular[1] === null ? 'removed' : self::money($regular[1])).'</td></tr>';
            }

            $html .= '</tbody></table>';
        }

        if (filled($request->seller_note)) {
            $html .= '<p>'.e($noteLabel).': '.e((string) $request->seller_note).'</p>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * The biggest price move in a request, for the admin queue. Rows are matched on channel, variation and minimum
     * quantity; the signed percentage with the largest size wins. A request that only adds tiers reads "new".
     * `alert` is true when any matched tier rises by more than 20% or falls by more than 50%.
     *
     * @return array{text: string, alert: bool}
     */
    public static function largestChange(ProductChangeRequest $request): array
    {
        $key = fn (array $row): string => implode('|', [$row['sales_channel_id'], $row['variation_id'] ?? '', $row['min_quantity']]);
        $old = collect($request->base_prices ?? [])->keyBy($key);
        $new = collect($request->proposed_prices ?? [])->keyBy($key);

        $largest = null;
        $alert = false;

        foreach ($old as $rowKey => $before) {
            $after = $new->get($rowKey);
            $oldPrice = (float) $before['unit_price'];

            if ($after === null || $oldPrice <= 0.0) {
                continue;
            }

            $percent = ((float) $after['unit_price'] - $oldPrice) / $oldPrice * 100;
            $alert = $alert || $percent > 20.0 || $percent < -50.0;

            if ($largest === null || abs($percent) > abs($largest)) {
                $largest = $percent;
            }
        }

        $added = $new->keys()->diff($old->keys())->isNotEmpty();

        if ($largest !== null && ! ($added && abs($largest) < 0.05)) {
            return ['text' => sprintf('%+.1f%%', $largest), 'alert' => $alert];
        }

        if ($added) {
            return ['text' => 'new', 'alert' => false];
        }

        if ($old->keys()->diff($new->keys())->isNotEmpty()) {
            return ['text' => 'removed', 'alert' => false];
        }

        return ['text' => '—', 'alert' => false];
    }

    /**
     * "Channel: Retail → Both" and "Category: A → B", for whichever changed.
     *
     * @return list<string>
     */
    public static function lines(ProductChangeRequest $request): array
    {
        $lines = [];

        if ($request->proposed_sales_channel_id !== null) {
            $names = SalesChannel::query()
                ->whereIn('id', [$request->base_sales_channel_id, $request->proposed_sales_channel_id])
                ->pluck('name', 'id');
            $lines[] = 'Channel: '.($names[$request->base_sales_channel_id] ?? '—').' → '.($names[$request->proposed_sales_channel_id] ?? '—');
        }

        if ($request->proposed_category_id !== null) {
            $names = Category::query()
                ->whereIn('id', [$request->base_category_id, $request->proposed_category_id])
                ->pluck('name', 'id');
            $lines[] = 'Category: '.($names[$request->base_category_id] ?? '—').' → '.($names[$request->proposed_category_id] ?? '—');
        }

        return $lines;
    }

    /**
     * Rows that differ between the prices the seller started from and the ones proposed, matched on channel,
     * variation and minimum quantity. A missing side means the row was added or removed.
     *
     * @return list<array{label: string, old: ?string, new: ?string}>
     */
    protected static function priceDifferences(ProductChangeRequest $request): array
    {
        $key = fn (array $row): string => implode('|', [$row['sales_channel_id'], $row['variation_id'] ?? '', $row['min_quantity']]);
        $old = collect($request->base_prices ?? [])->keyBy($key);
        $new = collect($request->proposed_prices ?? [])->keyBy($key);
        $channels = SalesChannel::query()->pluck('name', 'id');

        $rows = [];
        foreach ($old->keys()->merge($new->keys())->unique() as $rowKey) {
            $before = $old->get($rowKey);
            $after = $new->get($rowKey);
            $oldPrice = $before === null ? null : self::moneyOrNull($before['unit_price']);
            $newPrice = $after === null ? null : self::moneyOrNull($after['unit_price']);

            if ($oldPrice === $newPrice) {
                continue;
            }

            $row = $before ?? $after;
            $rows[] = [
                'label' => ($channels[$row['sales_channel_id']] ?? 'Price')
                    .(($row['variation_id'] ?? null) !== null ? ' (variation)' : '')
                    .' · from '.$row['min_quantity'].' pcs',
                'old' => $oldPrice,
                'new' => $newPrice,
            ];
        }

        return $rows;
    }

    protected static function moneyOrNull(int|float|string|null $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }
}
