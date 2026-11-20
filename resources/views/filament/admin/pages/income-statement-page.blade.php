<x-filament-panels::page>
    @php
        $data = $this->getStatementData();
        $revenues = $data['revenues'];
        $expenses = $data['expenses'];
        $isProfit = $data['is_profit'];
    @endphp

    <style>
        @media print {
            .fi-sidebar,
            .fi-topbar,
            .fi-header,
            .fi-page-header,
            .fi-page-header-main-ctn,
            .print\:hidden {
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
            .print\:block {
                display: block !important;
            }
            .print\:shadow-none {
                box-shadow: none !important;
            }
            .print\:border-0 {
                border: 0 !important;
            }
        }
    </style>

    {{-- Printable Formal Header (visible only on print) --}}
    <div class="hidden print:block mb-6 border-b pb-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-black uppercase tracking-wider">B2Bhaat Marketplace</h1>
                <h2 class="text-base font-semibold text-gray-800">Income Statement (Profit &amp; Loss)</h2>
                <p class="text-xs text-gray-500">Escrow &amp; Accounting Governance Division</p>
            </div>
            <div class="text-right text-xs text-gray-600">
                <p>Period: {{ $data['period_label'] }}</p>
                <p>Printed: {{ now()->format('d M Y, g:i A') }}</p>
                <p>Currency: BDT (৳)</p>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Executive KPI Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
            {{-- Total Revenue --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Revenue</span>
                    <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <x-heroicon-m-banknotes class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $revenues['formatted_total'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Gross marketplace earnings
                </p>
            </div>

            {{-- Total Expenses --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Expenses</span>
                    <span class="rounded-lg bg-rose-50 p-2 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                        <x-heroicon-m-arrow-trending-down class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $expenses['formatted_total'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Platform operational costs
                </p>
            </div>

            {{-- Net Profit / Loss --}}
            <div class="rounded-xl border {{ $isProfit ? 'border-emerald-200/80 bg-emerald-50/40 dark:border-emerald-800/60 dark:bg-emerald-950/20' : 'border-rose-200/80 bg-rose-50/40 dark:border-rose-800/60 dark:bg-rose-950/20' }} p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider {{ $isProfit ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                        {{ $isProfit ? 'Net Profit' : 'Net Loss' }}
                    </span>
                    <span class="rounded-lg {{ $isProfit ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-600 dark:bg-rose-900/60 dark:text-rose-300' }} p-2">
                        @if ($isProfit)
                            <x-heroicon-m-arrow-trending-up class="h-4 w-4" />
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                        @endif
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight {{ $isProfit ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">
                        {{ $data['formatted_net_income'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs {{ $isProfit ? 'text-emerald-700/90 dark:text-emerald-300/90' : 'text-rose-700/90 dark:text-rose-300/90' }}">
                    Net operational result
                </p>
            </div>

            {{-- Operating Profit Margin --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Operating Profit Margin</span>
                    <span class="rounded-lg bg-indigo-50 p-2 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                        <x-heroicon-m-chart-pie class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $data['formatted_operating_margin'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Net margin on revenue
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
                        Period: {{ $data['period_label'] }}
                    </span>
                </div>

                {{-- Quick Presets --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        wire:click="setPreset('month')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'month' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        This Month
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('last_month')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'last_month' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        Last Month
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
                        This Year
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('all')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'all' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        All Time
                    </button>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-2">
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
                </div>
            </div>
        </div>

        {{-- Financial Statement Table --}}
        <div class="rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 overflow-hidden print:border print:shadow-none">
            {{-- Table Header --}}
            <div class="border-b border-gray-200 bg-gray-50/75 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                        Income Statement (Profit &amp; Loss)
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Operational performance for period {{ $data['period_label'] }} · All amounts in BDT (৳)
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold {{ $isProfit ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300' }}">
                        {{ $isProfit ? 'Profitable' : 'Operating Deficit' }}
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-100/60 text-xs font-medium uppercase tracking-wider text-gray-600 dark:border-gray-800 dark:bg-gray-800/80 dark:text-gray-300">
                            <th scope="col" class="py-3 px-6 w-32">Account Code</th>
                            <th scope="col" class="py-3 px-6">Account Name / Line Item</th>
                            <th scope="col" class="py-3 px-6 text-right w-48">Amount (৳)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                        {{-- 1. OPERATING REVENUE SECTION --}}
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="3" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                1. Operating Revenue (4000 Series)
                            </td>
                        </tr>

                        @forelse ($revenues['accounts'] as $account)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-3 px-6 font-mono text-xs text-gray-500 dark:text-gray-400 font-semibold">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                        {{ $account['code'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-6 text-gray-900 dark:text-white font-medium">
                                    {{ $account['name'] }}
                                </td>
                                <td class="py-3 px-6 text-right font-mono font-medium text-gray-900 dark:text-white">
                                    {{ $account['formatted_amount'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-4 px-6 text-center text-xs text-gray-400">
                                    No revenue accounts recorded.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Revenue Subtotal --}}
                        <tr class="border-t-2 border-gray-200 bg-emerald-50/30 dark:border-gray-700 dark:bg-emerald-950/10 font-semibold">
                            <td class="py-3.5 px-6 text-xs text-emerald-800 dark:text-emerald-300 uppercase tracking-wider" colspan="2">
                                Total Operating Revenue
                            </td>
                            <td class="py-3.5 px-6 text-right font-mono text-base font-bold text-emerald-700 dark:text-emerald-400">
                                {{ $revenues['formatted_total'] }}
                            </td>
                        </tr>

                        {{-- 2. OPERATING EXPENSES SECTION --}}
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="3" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">
                                2. Operating Expenses (5000 Series)
                            </td>
                        </tr>

                        @forelse ($expenses['accounts'] as $account)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-3 px-6 font-mono text-xs text-gray-500 dark:text-gray-400 font-semibold">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                        {{ $account['code'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-6 text-gray-900 dark:text-white font-medium">
                                    {{ $account['name'] }}
                                </td>
                                <td class="py-3 px-6 text-right font-mono font-medium text-gray-900 dark:text-white">
                                    {{ $account['formatted_amount'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-4 px-6 text-center text-xs text-gray-400">
                                    No expense accounts recorded.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Expenses Subtotal --}}
                        <tr class="border-t-2 border-gray-200 bg-rose-50/30 dark:border-gray-700 dark:bg-rose-950/10 font-semibold">
                            <td class="py-3.5 px-6 text-xs text-rose-800 dark:text-rose-300 uppercase tracking-wider" colspan="2">
                                Total Operating Expenses
                            </td>
                            <td class="py-3.5 px-6 text-right font-mono text-base font-bold text-rose-700 dark:text-rose-400">
                                {{ $expenses['formatted_total'] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- High-Contrast Net Income Summary Banner --}}
            <div class="border-t-4 {{ $isProfit ? 'border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/30' : 'border-rose-500 bg-rose-50/60 dark:bg-rose-950/30' }} px-6 py-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider {{ $isProfit ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">
                                NET OPERATING INCOME / (LOSS)
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $isProfit ? 'bg-emerald-200/80 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-rose-200/80 text-rose-900 dark:bg-rose-900 dark:text-rose-200' }}">
                                {{ $isProfit ? 'Net Profit' : 'Net Loss' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs {{ $isProfit ? 'text-emerald-700/80 dark:text-emerald-400/80' : 'text-rose-700/80 dark:text-rose-400/80' }}">
                            Operating Margin: <span class="font-mono font-semibold">{{ $data['formatted_operating_margin'] }}</span> · Formatted per standard accounting conventions
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="text-3xl font-extrabold font-mono tracking-tight {{ $isProfit ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                            {{ $data['formatted_net_income'] }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Printable Audit Footer (visible only on print) --}}
        <div class="hidden print:block mt-12 pt-6 border-t border-gray-300 text-xs text-gray-600">
            <div class="flex justify-between items-end">
                <div>
                    <p class="font-semibold text-gray-800">B2Bhaat Marketplace Financial Governance</p>
                    <p>Internal Ledger &amp; Auditing System</p>
                    <p class="mt-1">Generated: {{ now()->toDateTimeString() }}</p>
                </div>
                <div class="text-center">
                    <div class="w-48 border-b border-gray-400 mb-1"></div>
                    <p>Authorized Controller Signature</p>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
