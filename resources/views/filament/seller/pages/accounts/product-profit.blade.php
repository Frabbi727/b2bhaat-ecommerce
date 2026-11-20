<x-filament-panels::page>
    @php
        $rows = $this->getReport();
        $totals = [
            'net_sales' => array_sum(array_column($rows, 'net_sales')),
            'cost' => array_sum(array_column($rows, 'cost')),
            'commission' => array_sum(array_column($rows, 'commission')),
            'profit' => array_sum(array_column($rows, 'profit')),
        ];
    @endphp

    <div class="space-y-6">
        @include('filament.seller.pages.accounts.partials.period-picker')

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">Product</th>
                            <th class="px-4 py-2 text-end font-medium">Net sales</th>
                            <th class="hidden px-4 py-2 text-end font-medium md:table-cell">Cost of goods</th>
                            <th class="hidden px-4 py-2 text-end font-medium md:table-cell">Commission</th>
                            <th class="px-4 py-2 text-end font-medium">Profit</th>
                            <th class="px-4 py-2 text-end font-medium">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="text-gray-950 dark:text-white">{{ $row['title'] }}</span>
                                    @if ($row['returns'] > 0)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $this->money($row['returns']) }} returned</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($row['net_sales']) }}</td>
                                <td class="hidden px-4 py-2 text-end font-mono tabular-nums text-gray-600 md:table-cell dark:text-gray-400">{{ $this->money($row['cost']) }}</td>
                                <td class="hidden px-4 py-2 text-end font-mono tabular-nums text-gray-600 md:table-cell dark:text-gray-400">{{ $this->money($row['commission']) }}</td>
                                <td @class(['px-4 py-2 text-end font-mono font-semibold tabular-nums', 'text-success-700 dark:text-success-400' => $row['profit'] >= 0, 'text-danger-600 dark:text-danger-400' => $row['profit'] < 0])>{{ $this->money($row['profit']) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ $row['margin'] }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-400">No product sales in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($rows !== [])
                        <tfoot class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                            <tr>
                                <td class="px-4 py-3 text-gray-950 dark:text-white">All products</td>
                                <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($totals['net_sales']) }}</td>
                                <td class="hidden px-4 py-3 text-end font-mono tabular-nums md:table-cell">{{ $this->money($totals['cost']) }}</td>
                                <td class="hidden px-4 py-3 text-end font-mono tabular-nums md:table-cell">{{ $this->money($totals['commission']) }}</td>
                                <td @class(['px-4 py-3 text-end font-mono tabular-nums', 'text-success-700 dark:text-success-400' => $totals['profit'] >= 0, 'text-danger-600 dark:text-danger-400' => $totals['profit'] < 0])>{{ $this->money($totals['profit']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Profit here is before your own running costs (rent, salaries…), which are not tied to one product — see Profit &amp; loss for the full picture. A product with no cost per unit set shows its whole sale as profit; set "Your cost per unit" on the product to fix that.
        </p>
    </div>
</x-filament-panels::page>
