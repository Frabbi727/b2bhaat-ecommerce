<x-filament-panels::page>
    @php
        $data = $this->getTrialBalanceData();
        $periods = $this->getPeriods();
        $grandTotal = $data['grand_total'];
        $isBalanced = $grandTotal['is_balanced'];
    @endphp

    <style>
        @media print {
            .fi-sidebar,
            .fi-topbar,
            .fi-page-header,
            .print\\:hidden {
                display: none !important;
            }
            .fi-main-ctn {
                padding: 0 !important;
                margin: 0 !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .print\\:block {
                display: block !important;
            }
            .print\\:shadow-none {
                box-shadow: none !important;
            }
            .print\\:border-0 {
                border: 0 !important;
            }
        }
    </style>

    {{-- Printable Formal Header (visible only on print) --}}
    <div class="hidden print:block mb-6 border-b pb-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-black uppercase tracking-wider">B2Bhaat Marketplace</h1>
                <h2 class="text-base font-semibold text-gray-800">General Ledger Trial Balance Statement</h2>
            </div>
            <div class="text-right text-xs text-gray-600">
                <p>Period: {{ $this->startDate ?? 'Beginning' }} to {{ $this->endDate ?? 'Present' }}</p>
                <p>Printed: {{ now()->format('d M Y, h:i A') }}</p>
                <p>Currency: BDT (৳)</p>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Executive KPI Equilibrium Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 print:hidden">
            {{-- Total Debits --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Debits</span>
                    <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <x-heroicon-m-arrow-down-left class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        ৳{{ number_format($grandTotal['total_debit'], 2) }}
                    </span>
                    <span class="text-xs text-gray-400 font-mono">BDT</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Net active debit position: <span class="font-mono font-medium text-emerald-600 dark:text-emerald-400">৳{{ number_format($grandTotal['total_net_debit'], 2) }}</span>
                </p>
            </div>

            {{-- Total Credits --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Credits</span>
                    <span class="rounded-lg bg-indigo-50 p-2 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                        <x-heroicon-m-arrow-up-right class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        ৳{{ number_format($grandTotal['total_credit'], 2) }}
                    </span>
                    <span class="text-xs text-gray-400 font-mono">BDT</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Net active credit position: <span class="font-mono font-medium text-indigo-600 dark:text-indigo-400">৳{{ number_format($grandTotal['total_net_credit'], 2) }}</span>
                </p>
            </div>

            {{-- Equilibrium Status --}}
            <div class="rounded-xl border {{ $isBalanced ? 'border-emerald-200/80 bg-emerald-50/40 dark:border-emerald-800/60 dark:bg-emerald-950/20' : 'border-rose-200/80 bg-rose-50/40 dark:border-rose-800/60 dark:bg-rose-950/20' }} p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider {{ $isBalanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">Equilibrium Check</span>
                    <span class="rounded-lg {{ $isBalanced ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-600 dark:bg-rose-900/60 dark:text-rose-300' }} p-2">
                        @if ($isBalanced)
                            <x-heroicon-m-check-badge class="h-4 w-4" />
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                        @endif
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-xl font-bold tracking-tight {{ $isBalanced ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">
                        {{ $isBalanced ? 'Books Balanced' : 'Out of Balance' }}
                    </span>
                    <span class="text-xs font-mono font-semibold {{ $isBalanced ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                        (Variance: ৳{{ number_format(abs($grandTotal['difference']), 2) }})
                    </span>
                </div>
                <p class="mt-1 text-xs {{ $isBalanced ? 'text-emerald-700/90 dark:text-emerald-300/90' : 'text-rose-700/90 dark:text-rose-300/90' }}">
                    {{ $isBalanced ? 'All posted entries satisfy double-entry mathematical integrity.' : 'Debit & Credit mismatch requires ledger review.' }}
                </p>
            </div>
        </div>

        {{-- Filter & Control Section --}}
        <div class="rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 print:hidden">
            <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-funnel class="h-4 w-4 text-gray-500" />
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">Report Parameters</span>
                    <span class="text-xs text-gray-400">·</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $this->startDate || $this->endDate ? 'Custom date range active' : 'Showing all historical posted entries' }}
                    </span>
                </div>

                {{-- Quick Presets --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        wire:click="setPreset('all')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'all' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        All Time
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('month')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'month' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        This Month
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('quarter')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'quarter' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        This Quarter
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('year')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'year' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        Fiscal Year
                    </button>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end">
                    {{-- Period Selector --}}
                    <div>
                        <label for="periodId" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Fiscal Accounting Period
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select
                                id="periodId"
                                wire:model.live="periodId"
                            >
                                <option value="">Custom / All Periods</option>
                                @foreach ($periods as $period)
                                    <option value="{{ $period->id }}">
                                        {{ $period->fiscalYear?->name ? $period->fiscalYear->name . ' — ' : '' }}{{ $period->name }} ({{ $period->start_date?->format('d M Y') }} - {{ $period->end_date?->format('d M Y') }})
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    {{-- Start Date --}}
                    <div>
                        <label for="startDate" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            From Date
                        </label>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-calendar">
                            <x-filament::input
                                type="date"
                                id="startDate"
                                wire:model.live="startDate"
                            />
                        </x-filament::input.wrapper>
                    </div>

                    {{-- End Date --}}
                    <div>
                        <label for="endDate" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            To Date
                        </label>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-calendar">
                            <x-filament::input
                                type="date"
                                id="endDate"
                                wire:model.live="endDate"
                            />
                        </x-filament::input.wrapper>
                    </div>

                    {{-- Hide Zero Balances & Action Buttons --}}
                    <div class="flex items-center justify-between gap-2 pt-1">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                wire:model.live="hideZeroBalances"
                                class="rounded border-gray-300 dark:border-gray-700 text-primary-600 shadow-sm focus:ring-primary-500"
                            />
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Hide Zero Balances</span>
                        </label>

                        <div class="flex items-center gap-1.5">
                            <x-filament::button
                                color="gray"
                                size="sm"
                                wire:click="resetFilters"
                                icon="heroicon-m-arrow-path"
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
            </div>
        </div>

        {{-- Verification Alert Banner --}}
        @if ($isBalanced)
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800/60 dark:bg-emerald-950/40">
                <div class="flex items-center gap-3">
                    <div class="rounded-full bg-emerald-500/20 p-2 text-emerald-600 dark:text-emerald-400">
                        <x-heroicon-m-check-circle class="h-6 w-6" />
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
                        <x-heroicon-m-exclamation-triangle class="h-6 w-6" />
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

        {{-- Financial Statement Table --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 print:shadow-none print:border-gray-300">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="sticky top-0 z-10 bg-gray-50/95 backdrop-blur text-xs font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-800/90 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
                        <tr>
                            <th scope="col" class="py-3.5 pl-6 pr-3 w-32">Account Code</th>
                            <th scope="col" class="px-3 py-3.5">Account Name</th>
                            <th scope="col" class="px-3 py-3.5 text-right w-36">Total Debit (৳)</th>
                            <th scope="col" class="px-3 py-3.5 text-right w-36">Total Credit (৳)</th>
                            <th scope="col" class="px-3 py-3.5 text-right w-36">Net Debit (৳)</th>
                            <th scope="col" class="py-3.5 pl-3 pr-6 text-right w-36">Net Credit (৳)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                        @foreach ($data['categories'] as $catKey => $category)
                            @if (count($category['accounts']) > 0 || ! $this->hideZeroBalances)
                                {{-- Category Header Band --}}
                                <tr class="bg-gray-100/80 font-bold text-gray-900 dark:bg-gray-800/90 dark:text-gray-100 border-t border-b border-gray-200 dark:border-gray-700">
                                    <td colspan="6" class="py-2.5 pl-6 pr-6">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs uppercase tracking-wider">{{ $category['display_name'] ?? $category['name'] }}</span>
                                            <span class="text-[11px] font-normal text-gray-500 dark:text-gray-400">
                                                Code Range: <span class="font-mono">{{ $category['code_range'] ?? '' }}</span> ({{ count($category['accounts']) }} accounts)
                                            </span>
                                        </div>
                                    </td>
                                </tr>

                                {{-- Account Rows --}}
                                @forelse ($category['accounts'] as $account)
                                    <tr class="hover:bg-gray-50/70 dark:hover:bg-gray-800/40 text-gray-700 dark:text-gray-300 transition-colors">
                                        <td class="py-2.5 pl-6 pr-3">
                                            <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 font-mono text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                                {{ $account['code'] }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $account['name'] }}</span>
                                                @if ($account['is_system'] ?? false)
                                                    <span class="rounded bg-gray-100 px-1.5 py-0.2 text-[10px] text-gray-500 dark:bg-gray-800 dark:text-gray-400 font-mono">system</span>
                                                @endif
                                                @if ($account['system_slug'])
                                                    <span class="text-[11px] text-gray-400 font-mono">({{ $account['system_slug'] }})</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $account['total_debit'] > 0 ? number_format($account['total_debit'], 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $account['total_credit'] > 0 ? number_format($account['total_credit'], 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums font-medium {{ $account['net_debit'] > 0 ? 'text-emerald-700 dark:text-emerald-400 font-semibold' : 'text-gray-400' }}">
                                            {{ $account['net_debit'] > 0 ? number_format($account['net_debit'], 2) : '—' }}
                                        </td>
                                        <td class="py-2.5 pl-3 pr-6 text-right font-mono tabular-nums font-medium {{ $account['net_credit'] > 0 ? 'text-indigo-700 dark:text-indigo-400 font-semibold' : 'text-gray-400' }}">
                                            {{ $account['net_credit'] > 0 ? number_format($account['net_credit'], 2) : '—' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr class="text-xs text-gray-400 italic">
                                        <td colspan="6" class="py-3 pl-8 pr-6 text-center">
                                            No active accounts with non-zero balances in this category.
                                        </td>
                                    </tr>
                                @endforelse

                                {{-- Category Subtotal Row --}}
                                <tr class="bg-gray-50/90 font-semibold text-gray-900 dark:bg-gray-800/60 dark:text-gray-200 border-t border-b border-gray-200 dark:border-gray-700">
                                    <td class="py-2.5 pl-6 pr-3 text-xs uppercase tracking-wider text-gray-600 dark:text-gray-400" colspan="2">
                                        Subtotal: {{ $category['name'] }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                        {{ number_format($category['total_debit'], 2) }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                        {{ number_format($category['total_credit'], 2) }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono tabular-nums text-emerald-700 dark:text-emerald-400">
                                        {{ number_format($category['total_net_debit'], 2) }}
                                    </td>
                                    <td class="py-2.5 pl-3 pr-6 text-right font-mono tabular-nums text-indigo-700 dark:text-indigo-400">
                                        {{ number_format($category['total_net_credit'], 2) }}
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>

                    {{-- Final Grand Total Row (Traditional Double-Underline) --}}
                    <tfoot>
                        <tr class="border-t-2 border-b-4 border-double border-gray-900 bg-gray-100/90 font-bold text-gray-950 dark:border-gray-200 dark:bg-gray-800 dark:text-white">
                            <td class="py-4 pl-6 pr-3 text-sm uppercase tracking-wider" colspan="2">
                                <div class="flex items-center gap-2">
                                    <span>Grand Total</span>
                                    @if ($isBalanced)
                                        <span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                            Equilibrium OK
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-4 text-right font-mono tabular-nums text-base">
                                {{ number_format($grandTotal['total_debit'], 2) }}
                            </td>
                            <td class="px-3 py-4 text-right font-mono tabular-nums text-base">
                                {{ number_format($grandTotal['total_credit'], 2) }}
                            </td>
                            <td class="px-3 py-4 text-right font-mono tabular-nums text-base text-emerald-800 dark:text-emerald-300">
                                {{ number_format($grandTotal['total_net_debit'], 2) }}
                            </td>
                            <td class="py-4 pl-3 pr-6 text-right font-mono tabular-nums text-base text-indigo-800 dark:text-indigo-300">
                                {{ number_format($grandTotal['total_net_credit'], 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
