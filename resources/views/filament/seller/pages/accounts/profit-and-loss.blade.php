<x-filament-panels::page>
    @php($report = $this->getReport())

    <div class="space-y-6">
        @include('filament.seller.pages.accounts.partials.period-picker')

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Net sales</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['net_sales']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Gross profit</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['gross_profit']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $report['gross_margin'] }}% of sales after cost of goods</p>
            </div>
            <div @class([
                'rounded-xl border p-4 shadow-sm',
                'border-success-200 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' => $report['is_profit'],
                'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' => ! $report['is_profit'],
            ])>
                <p @class(['text-xs font-medium uppercase tracking-wide', 'text-success-700 dark:text-success-400' => $report['is_profit'], 'text-danger-700 dark:text-danger-400' => ! $report['is_profit']])>
                    {{ $report['is_profit'] ? 'Net profit' : 'Net loss' }}
                </p>
                <p @class(['mt-1 font-mono text-2xl font-semibold tabular-nums', 'text-success-800 dark:text-success-300' => $report['is_profit'], 'text-danger-800 dark:text-danger-300' => ! $report['is_profit']])>
                    {{ $this->money($report['net_profit']) }}
                </p>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $report['net_margin'] }}% of sales</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-baseline justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Statement</h2>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->periodLabel() }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    @include('filament.seller.pages.accounts.partials.statement-rows', ['title' => 'Income', 'rows' => $report['income'], 'total' => $report['net_sales'], 'totalLabel' => 'Net sales'])

                    <tbody>
                        <tr>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">Less: cost of goods sold</td>
                            <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money(-$report['cost_of_goods_sold']) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="px-4 py-2 text-gray-950 dark:text-white">Gross profit</td>
                            <td class="border-t border-gray-300 px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:border-white/20 dark:text-white">{{ $this->money($report['gross_profit']) }}</td>
                        </tr>
                    </tbody>

                    @include('filament.seller.pages.accounts.partials.statement-rows', ['title' => 'Expenses', 'rows' => $report['expenses'], 'total' => -$report['total_expenses'], 'totalLabel' => 'Total expenses', 'negate' => true])

                    <tbody>
                        <tr @class(['text-base font-bold', 'bg-success-50 dark:bg-success-500/10' => $report['is_profit'], 'bg-danger-50 dark:bg-danger-500/10' => ! $report['is_profit']])>
                            <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $report['is_profit'] ? 'Net profit' : 'Net loss' }}</td>
                            <td @class(['border-t-2 border-double border-gray-400 px-4 py-3 text-end font-mono tabular-nums', 'text-success-700 dark:text-success-400' => $report['is_profit'], 'text-danger-700 dark:text-danger-400' => ! $report['is_profit']])>{{ $this->money($report['net_profit']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            B2Bhaat sales count when the buyer's money is held for you (paid online, or delivered for cash on delivery). Cost of goods uses the cost per unit on each product when it was ordered. Owner withdrawals are not expenses and are not shown here.
        </p>
    </div>
</x-filament-panels::page>
