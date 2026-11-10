<x-filament-panels::page>
    @php
        $data = $this->getStatementData();
        $assets = $data['assets'];
        $liabilities = $data['liabilities'];
        $equity = $data['equity'];
        $isBalanced = $data['is_balanced'];
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
                <h2 class="text-base font-semibold text-gray-800">Balance Sheet Statement</h2>
                <p class="text-xs text-gray-500">Escrow &amp; Accounting Governance Division</p>
            </div>
            <div class="text-right text-xs text-gray-600">
                <p>As of Date: {{ $data['formatted_as_of_date'] }}</p>
                <p>Printed: {{ now()->format('d M Y, g:i A') }}</p>
                <p>Currency: BDT (৳)</p>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Executive KPI Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
            {{-- Total Assets --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Assets</span>
                    <span class="rounded-lg bg-sky-50 p-2 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                        <x-heroicon-m-building-library class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $assets['formatted_total'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Cash, bank &amp; receivables
                </p>
            </div>

            {{-- Total Liabilities --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Liabilities</span>
                    <span class="rounded-lg bg-amber-50 p-2 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                        <x-heroicon-m-lock-closed class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $liabilities['formatted_total'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Escrow held &amp; seller obligations
                </p>
            </div>

            {{-- Total Equity --}}
            <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Equity</span>
                    <span class="rounded-lg bg-violet-50 p-2 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                        <x-heroicon-m-shield-check class="h-4 w-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">
                        {{ $equity['formatted_total'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Owner capital &amp; cumulative earnings
                </p>
            </div>

            {{-- Balance Check (A = L + E) --}}
            <div class="rounded-xl border {{ $isBalanced ? 'border-emerald-200/80 bg-emerald-50/40 dark:border-emerald-800/60 dark:bg-emerald-950/20' : 'border-rose-200/80 bg-rose-50/40 dark:border-rose-800/60 dark:bg-rose-950/20' }} p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider {{ $isBalanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                        Balance Equilibrium
                    </span>
                    <span class="rounded-lg {{ $isBalanced ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-600 dark:bg-rose-900/60 dark:text-rose-300' }} p-2">
                        @if ($isBalanced)
                            <x-heroicon-m-check-badge class="h-4 w-4" />
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                        @endif
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold font-mono tracking-tight {{ $isBalanced ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">
                        @if ($isBalanced)
                            Balanced
                        @else
                            Out of Balance
                        @endif
                    </span>
                </div>
                <p class="mt-1 text-xs font-mono {{ $isBalanced ? 'text-emerald-700/90 dark:text-emerald-300/90' : 'text-rose-700/90 dark:text-rose-300/90 font-semibold' }}">
                    @if ($isBalanced)
                        Balanced ({{ $data['formatted_discrepancy'] }} Diff)
                    @else
                        Discrepancy: {{ $data['formatted_discrepancy'] }}
                    @endif
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
                        As of Date: {{ $data['formatted_as_of_date'] }}
                    </span>
                </div>

                {{-- Quick Presets --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        wire:click="setPreset('today')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'today' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        Today
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('month')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'month' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        End of Month
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('last_month')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'last_month' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        End of Last Month
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('quarter')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'quarter' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        End of Quarter
                    </button>
                    <button
                        type="button"
                        wire:click="setPreset('year')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors {{ $this->preset === 'year' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 font-semibold' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' }}"
                    >
                        End of Year
                    </button>
                </div>
            </div>

            <div class="p-5">
                <div class="max-w-xs">
                    {{-- As of Date --}}
                    <label for="asOfDate" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        As of Date
                    </label>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-calendar">
                        <x-filament::input
                            type="date"
                            id="asOfDate"
                            wire:model.live="asOfDate"
                        />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </div>

        {{-- Financial Statement Table --}}
        <div class="rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 overflow-hidden print:border print:shadow-none">
            {{-- Table Header --}}
            <div class="border-b border-gray-200 bg-gray-50/75 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                        Balance Sheet (Statement of Financial Position)
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Point-in-time financial posture as of {{ $data['formatted_as_of_date'] }} · All amounts in BDT (৳)
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold {{ $isBalanced ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300' }}">
                        {{ $isBalanced ? 'Balanced Equilibrium' : 'Equilibrium Discrepancy' }}
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
                        {{-- 1. CURRENT ASSETS SECTION --}}
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="3" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-sky-700 dark:text-sky-400">
                                1. Current Assets (1000 Series)
                            </td>
                        </tr>

                        @forelse ($assets['accounts'] as $account)
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
                                    No asset accounts recorded.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Current Assets Subtotal --}}
                        <tr class="border-t-2 border-gray-200 bg-sky-50/30 dark:border-gray-700 dark:bg-sky-950/10 font-semibold">
                            <td class="py-3.5 px-6 text-xs text-sky-800 dark:text-sky-300 uppercase tracking-wider" colspan="2">
                                Total Current Assets
                            </td>
                            <td class="py-3.5 px-6 text-right font-mono text-base font-bold text-sky-700 dark:text-sky-400">
                                {{ $assets['formatted_total'] }}
                            </td>
                        </tr>

                        {{-- 2. CURRENT LIABILITIES SECTION --}}
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="3" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                                2. Current Liabilities (2000 Series)
                            </td>
                        </tr>

                        @forelse ($liabilities['accounts'] as $account)
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
                                    No liability accounts recorded.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Current Liabilities Subtotal --}}
                        <tr class="border-t-2 border-gray-200 bg-amber-50/30 dark:border-gray-700 dark:bg-amber-950/10 font-semibold">
                            <td class="py-3.5 px-6 text-xs text-amber-800 dark:text-amber-300 uppercase tracking-wider" colspan="2">
                                Total Current Liabilities
                            </td>
                            <td class="py-3.5 px-6 text-right font-mono text-base font-bold text-amber-700 dark:text-amber-400">
                                {{ $liabilities['formatted_total'] }}
                            </td>
                        </tr>

                        {{-- 3. EQUITY & CAPITAL SECTION --}}
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="3" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-violet-700 dark:text-violet-400">
                                3. Equity &amp; Capital (3000 Series)
                            </td>
                        </tr>

                        @forelse ($equity['capital_accounts'] as $account)
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
                                    No capital accounts recorded.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Dynamic Cumulative Net Income Line Item --}}
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors bg-violet-50/20 dark:bg-violet-950/10">
                            <td class="py-3 px-6 font-mono text-xs text-gray-500 dark:text-gray-400 font-semibold">
                                <span class="px-2 py-0.5 rounded bg-violet-100 dark:bg-violet-900 text-violet-800 dark:text-violet-200">
                                    ROLLUP
                                </span>
                            </td>
                            <td class="py-3 px-6 text-gray-900 dark:text-white font-medium">
                                Cumulative Net Income (Unclosed)
                                <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                    Operating earnings rolled into equity
                                </span>
                            </td>
                            <td class="py-3 px-6 text-right font-mono font-medium text-gray-900 dark:text-white">
                                {{ $equity['formatted_current_net_income'] }}
                            </td>
                        </tr>

                        {{-- Equity Subtotal --}}
                        <tr class="border-t-2 border-gray-200 bg-violet-50/30 dark:border-gray-700 dark:bg-violet-950/10 font-semibold">
                            <td class="py-3.5 px-6 text-xs text-violet-800 dark:text-violet-300 uppercase tracking-wider" colspan="2">
                                Total Equity &amp; Retained Earnings
                            </td>
                            <td class="py-3.5 px-6 text-right font-mono text-base font-bold text-violet-700 dark:text-violet-400">
                                {{ $equity['formatted_total'] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- High-Contrast Parity Verification Footer --}}
            <div class="border-t-4 {{ $isBalanced ? 'border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/30' : 'border-rose-500 bg-rose-50/60 dark:bg-rose-950/30' }} px-6 py-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider {{ $isBalanced ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">
                                ACCOUNTING EQUATION EQUILIBRIUM (ASSETS = LIABILITIES + EQUITY)
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $isBalanced ? 'bg-emerald-200/80 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-rose-200/80 text-rose-900 dark:bg-rose-900 dark:text-rose-200' }}">
                                {{ $isBalanced ? 'Balanced' : 'Discrepancy Detected' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs {{ $isBalanced ? 'text-emerald-700/80 dark:text-emerald-400/80' : 'text-rose-700/80 dark:text-rose-400/80' }}">
                            Total Assets: <span class="font-mono font-semibold">{{ $assets['formatted_total'] }}</span> · Total Liabilities &amp; Equity: <span class="font-mono font-semibold">{{ $data['formatted_total_liabilities_and_equity'] }}</span>
                            @if (! $isBalanced)
                                · Discrepancy: <span class="font-mono font-bold">{{ $data['formatted_discrepancy'] }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 block mb-0.5">Total Liabilities &amp; Equity</span>
                        <span class="text-3xl font-extrabold font-mono tracking-tight {{ $isBalanced ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                            {{ $data['formatted_total_liabilities_and_equity'] }}
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
                    <p>Internal Ledger &amp; Balance Sheet Audit</p>
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
