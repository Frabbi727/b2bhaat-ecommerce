<x-filament-panels::page>
    @php($report = $this->getReport())

    <div class="space-y-6">
        @include('filament.seller.pages.accounts.partials.as-of-picker')

        @unless ($report['is_balanced'])
            <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                The balance sheet does not balance. Please contact B2Bhaat support.
            </div>
        @endunless

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-baseline justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">What you own</h2>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->asOfLabel() }}</span>
                </div>
                <table class="w-full text-sm">
                    @include('filament.seller.pages.accounts.partials.statement-rows', ['title' => 'Assets', 'rows' => $report['assets'], 'total' => $report['total_assets'], 'totalLabel' => 'Total assets'])
                </table>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-baseline justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">What you owe, and your stake</h2>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->asOfLabel() }}</span>
                </div>
                <table class="w-full text-sm">
                    @include('filament.seller.pages.accounts.partials.statement-rows', ['title' => 'Liabilities', 'rows' => $report['liabilities'], 'total' => $report['total_liabilities'], 'totalLabel' => 'Total liabilities'])

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th colspan="2" class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Capital</th>
                        </tr>
                        @foreach ($report['equity'] as $row)
                            <tr>
                                <td class="px-4 py-2 ps-6 text-gray-700 dark:text-gray-300"><span class="me-2 font-mono text-xs text-gray-400">{{ $row['code'] }}</span>{{ $row['name'] }}</td>
                                <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'text-danger-600 dark:text-danger-400' => $row['amount'] < 0, 'text-gray-950 dark:text-white' => $row['amount'] >= 0])>{{ $this->money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="px-4 py-2 ps-6 text-gray-700 dark:text-gray-300">{{ $report['profit_to_date'] >= 0 ? 'Profit to date' : 'Loss to date' }}</td>
                            <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'text-danger-600 dark:text-danger-400' => $report['profit_to_date'] < 0, 'text-gray-950 dark:text-white' => $report['profit_to_date'] >= 0])>{{ $this->money($report['profit_to_date']) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="px-4 py-2 text-gray-950 dark:text-white">Total capital</td>
                            <td class="border-t border-gray-300 px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:border-white/20 dark:text-white">{{ $this->money($report['total_equity']) }}</td>
                        </tr>
                    </tbody>

                    <tbody>
                        <tr class="text-base font-bold">
                            <td class="px-4 py-3 text-gray-950 dark:text-white">Liabilities + capital</td>
                            <td class="border-t-2 border-double border-gray-400 px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['total_liabilities'] + $report['total_equity']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            "B2Bhaat – money on hold" and "available balance" always match your wallet. A negative available balance means you owe B2Bhaat that amount.
        </p>
    </div>
</x-filament-panels::page>
