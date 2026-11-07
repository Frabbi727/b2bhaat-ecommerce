<x-filament-panels::page>
    @php
        $data = $this->getTrialBalanceData();
        $periods = $this->getPeriods();
        $grandTotal = $data['grand_total'];
        $isBalanced = $grandTotal['is_balanced'];
    @endphp

    <div class="space-y-6">
        {{-- Filters Section --}}
        <x-filament::section>
            <x-slot name="heading">
                Report Filters
            </x-slot>
            <x-slot name="description">
                Filter the trial balance report by accounting period, custom date range, or toggle zero balance accounts.
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end">
                {{-- Period Selector --}}
                <div>
                    <label for="periodId" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Accounting Period
                    </label>
                    <select
                        id="periodId"
                        wire:model.live="periodId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    >
                        <option value="">All Periods</option>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}">
                                {{ $period->fiscalYear?->name ? $period->fiscalYear->name . ' — ' : '' }}{{ $period->name }} ({{ $period->start_date?->format('d M Y') }} - {{ $period->end_date?->format('d M Y') }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Start Date --}}
                <div>
                    <label for="startDate" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Start Date
                    </label>
                    <input
                        type="date"
                        id="startDate"
                        wire:model.live="startDate"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>

                {{-- End Date --}}
                <div>
                    <label for="endDate" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        End Date
                    </label>
                    <input
                        type="date"
                        id="endDate"
                        wire:model.live="endDate"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>

                {{-- Hide Zero Balances & Action Buttons --}}
                <div class="flex flex-col justify-end space-y-2">
                    <label class="inline-flex items-center space-x-2 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="hideZeroBalances"
                            class="rounded border-gray-300 dark:border-gray-700 text-primary-600 shadow-sm focus:ring-primary-500"
                        />
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Hide Zero Balances</span>
                    </label>

                    <div class="flex items-center space-x-2 pt-1">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            wire:click="resetFilters"
                        >
                            Reset
                        </x-filament::button>

                        <x-filament::button
                            color="primary"
                            size="sm"
                            wire:click="applyFilters"
                        >
                            Apply
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Balance Status Banner --}}
        @if ($isBalanced)
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800/60 dark:bg-emerald-950/40">
                <div class="flex items-center gap-3">
                    <div class="rounded-full bg-emerald-500/20 p-2 text-emerald-600 dark:text-emerald-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-base font-semibold text-emerald-900 dark:text-emerald-200">
                            Books Balanced: Total Debits equal Total Credits (৳{{ number_format($grandTotal['total_debit'], 2) }})
                        </h4>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300">
                            Net Debit and Net Credit balances are in exact equilibrium (৳{{ number_format($grandTotal['total_net_debit'], 2) }}). All ledger entries satisfy double-entry accounting integrity.
                        </p>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-800/60 dark:bg-rose-950/40">
                <div class="flex items-center gap-3">
                    <div class="rounded-full bg-rose-500/20 p-2 text-rose-600 dark:text-rose-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-base font-semibold text-rose-900 dark:text-rose-200">
                            Out of Balance: Difference of ৳{{ number_format(abs($grandTotal['difference']), 2) }}
                        </h4>
                        <p class="text-sm text-rose-700 dark:text-rose-300">
                            Total Debits (৳{{ number_format($grandTotal['total_debit'], 2) }}) do not equal Total Credits (৳{{ number_format($grandTotal['total_credit'], 2) }}). Immediate review of posted entries is required.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Financial Report Table --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-3.5 pl-6 pr-3">Account Code</th>
                            <th scope="col" class="px-3 py-3.5">Account Name</th>
                            <th scope="col" class="px-3 py-3.5 text-right">Total Debit (৳)</th>
                            <th scope="col" class="px-3 py-3.5 text-right">Total Credit (৳)</th>
                            <th scope="col" class="px-3 py-3.5 text-right">Net Debit (৳)</th>
                            <th scope="col" class="py-3.5 pl-3 pr-6 text-right">Net Credit (৳)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($data['categories'] as $catKey => $category)
                            @if (count($category['accounts']) > 0 || ! $this->hideZeroBalances)
                                {{-- Category Header --}}
                                <tr class="bg-gray-100/70 font-semibold text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                                    <td colspan="6" class="py-2.5 pl-6 pr-6 text-sm tracking-wide">
                                        {{ $category['display_name'] ?? $category['name'] }}
                                    </td>
                                </tr>

                                {{-- Account Rows --}}
                                @forelse ($category['accounts'] as $account)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 text-gray-700 dark:text-gray-300">
                                        <td class="py-2.5 pl-8 pr-3 font-mono text-xs font-semibold text-gray-900 dark:text-gray-200">
                                            {{ $account['code'] }}
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <span>{{ $account['name'] }}</span>
                                            @if ($account['system_slug'])
                                                <span class="ml-2 text-xs text-gray-400 font-mono">({{ $account['system_slug'] }})</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                            {{ $account['total_debit'] > 0 ? number_format($account['total_debit'], 2) : '-' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                            {{ $account['total_credit'] > 0 ? number_format($account['total_credit'], 2) : '-' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums font-medium text-emerald-700 dark:text-emerald-400">
                                            {{ $account['net_debit'] > 0 ? number_format($account['net_debit'], 2) : '-' }}
                                        </td>
                                        <td class="py-2.5 pl-3 pr-6 text-right font-mono tabular-nums font-medium text-indigo-700 dark:text-indigo-400">
                                            {{ $account['net_credit'] > 0 ? number_format($account['net_credit'], 2) : '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr class="text-xs text-gray-400 italic">
                                        <td colspan="6" class="py-2 pl-8 pr-6">
                                            No accounts in this category with non-zero balances.
                                        </td>
                                    </tr>
                                @endforelse

                                {{-- Category Subtotal Row --}}
                                <tr class="bg-gray-50/80 font-medium text-gray-900 dark:bg-gray-800/50 dark:text-gray-200 border-t border-b border-gray-200 dark:border-gray-700">
                                    <td class="py-2 pl-8 pr-3 font-semibold text-xs uppercase text-gray-500 dark:text-gray-400" colspan="2">
                                        Subtotal: {{ $category['name'] }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold">
                                        {{ number_format($category['total_debit'], 2) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold">
                                        {{ number_format($category['total_credit'], 2) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">
                                        {{ number_format($category['total_net_debit'], 2) }}
                                    </td>
                                    <td class="py-2 pl-3 pr-6 text-right font-mono tabular-nums font-semibold text-indigo-700 dark:text-indigo-400">
                                        {{ number_format($category['total_net_credit'], 2) }}
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>

                    {{-- Final Grand Total Row --}}
                    <tfoot>
                        <tr class="border-t-2 border-b-2 border-gray-900 bg-gray-100 font-bold text-gray-950 dark:border-gray-100 dark:bg-gray-800 dark:text-white">
                            <td class="py-3.5 pl-6 pr-3 text-base uppercase" colspan="2">
                                Grand Total
                            </td>
                            <td class="px-3 py-3.5 text-right font-mono tabular-nums text-base">
                                {{ number_format($grandTotal['total_debit'], 2) }}
                            </td>
                            <td class="px-3 py-3.5 text-right font-mono tabular-nums text-base">
                                {{ number_format($grandTotal['total_credit'], 2) }}
                            </td>
                            <td class="px-3 py-3.5 text-right font-mono tabular-nums text-base text-emerald-800 dark:text-emerald-300">
                                {{ number_format($grandTotal['total_net_debit'], 2) }}
                            </td>
                            <td class="py-3.5 pl-3 pr-6 text-right font-mono tabular-nums text-base text-indigo-800 dark:text-indigo-300">
                                {{ number_format($grandTotal['total_net_credit'], 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
