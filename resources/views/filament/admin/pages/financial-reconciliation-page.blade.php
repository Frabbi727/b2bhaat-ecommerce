<x-filament-panels::page>
    @php
        $metrics = $this->getReconciliationMetrics();
        $sellersData = $this->getSellersAuditData();
        $allReconciled = $metrics['seller_wallet']['is_reconciled']
            && $metrics['seller_escrow']['is_reconciled']
            && $metrics['reserved_payouts']['is_reconciled']
            && $metrics['customer_refunds']['is_reconciled']
            && $sellersData['unreconciled_count'] === 0;
    @endphp

    <div class="space-y-6">
        {{-- Header Controls: Last Audited & Run Audit Action --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $allReconciled ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400' }}">
                    <x-heroicon-o-shield-check class="h-6 w-6" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                        Sub-Ledger vs General Ledger Integrity
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($this->lastAuditedAt)
                            Last verified: {{ \Carbon\Carbon::parse($this->lastAuditedAt)->diffForHumans() }} ({{ $this->lastAuditedAt }})
                        @else
                            Audit not yet run
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button
                    wire:click="runAudit"
                    icon="heroicon-o-arrow-path"
                    color="primary"
                    size="sm"
                >
                    Run Full Audit
                </x-filament::button>
            </div>
        </div>

        {{-- 4 Core Reconciliation Metric Cards Grid --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Seller Wallet Payables --}}
            @php $m1 = $metrics['seller_wallet']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $m1['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $m1['is_reconciled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                        @if ($m1['is_reconciled'])
                            <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                        @endif
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>Sub-Ledger Total:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m1['sub_ledger_total'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>General Ledger:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m1['gl_total'], 2) }}</span>
                    </div>
                    <div class="border-t border-gray-100 pt-2 dark:border-gray-800 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Variance:</span>
                        <span class="font-mono font-bold {{ $m1['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            ৳{{ number_format($m1['discrepancy'], 2) }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Card 2: Seller Escrow Payables --}}
            @php $m2 = $metrics['seller_escrow']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $m2['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $m2['is_reconciled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                        @if ($m2['is_reconciled'])
                            <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                        @endif
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>Sub-Ledger Total:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m2['sub_ledger_total'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>General Ledger:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m2['gl_total'], 2) }}</span>
                    </div>
                    <div class="border-t border-gray-100 pt-2 dark:border-gray-800 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Variance:</span>
                        <span class="font-mono font-bold {{ $m2['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            ৳{{ number_format($m2['discrepancy'], 2) }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Card 3: Reserved Payout Payables --}}
            @php $m3 = $metrics['reserved_payouts']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $m3['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $m3['is_reconciled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                        @if ($m3['is_reconciled'])
                            <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                        @endif
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>Pending Payouts:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m3['sub_ledger_total'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>General Ledger:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m3['gl_total'], 2) }}</span>
                    </div>
                    <div class="border-t border-gray-100 pt-2 dark:border-gray-800 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Variance:</span>
                        <span class="font-mono font-bold {{ $m3['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            ৳{{ number_format($m3['discrepancy'], 2) }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Card 4: Customer Refund Payables --}}
            @php $m4 = $metrics['customer_refunds']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $m4['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $m4['is_reconciled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                        @if ($m4['is_reconciled'])
                            <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                        @endif
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>Pending Refunds:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m4['sub_ledger_total'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                        <span>General Ledger:</span>
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">৳{{ number_format($m4['gl_total'], 2) }}</span>
                    </div>
                    <div class="border-t border-gray-100 pt-2 dark:border-gray-800 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Variance:</span>
                        <span class="font-mono font-bold {{ $m4['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            ৳{{ number_format($m4['discrepancy'], 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Seller Sub-Ledger Discrepancy Table --}}
        <x-filament::section>
            <x-slot name="heading">
                Seller Sub-Ledger Verification
            </x-slot>
            <x-slot name="description">
                Per-seller reconciliation breakdown comparing individual wallet sub-ledgers with seller-tagged general ledger liability balances.
            </x-slot>

            <x-slot name="headerEnd">
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-gray-700 dark:text-gray-300">
                        <input
                            type="checkbox"
                            wire:model.live="showOnlyDiscrepancies"
                            class="rounded border-gray-300 dark:border-gray-700 text-primary-600 shadow-sm focus:ring-primary-500"
                        />
                        <span>Show Only Discrepancies</span>
                    </label>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Total Sellers: <strong class="text-gray-900 dark:text-gray-100">{{ $sellersData['total_sellers'] }}</strong>
                        ({{ $sellersData['reconciled_count'] }} Reconciled, {{ $sellersData['unreconciled_count'] }} Discrepant)
                    </span>
                </div>
            </x-slot>

            @if (count($sellersData['items']) === 0)
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <x-heroicon-o-check-circle class="h-8 w-8" />
                    </div>
                    <h4 class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">
                        All Seller Sub-Ledgers In Sync
                    </h4>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-sm">
                        @if ($this->showOnlyDiscrepancies)
                            No seller sub-ledger discrepancies found matching the active filter.
                        @else
                            No sellers registered or all registered seller wallets have balanced entries.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-800/50">
                                <th class="py-3 px-3 font-semibold text-gray-700 dark:text-gray-300">Seller</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">Wallet Avail</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">GL Avail</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">Escrow Wallet</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">GL Escrow</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">Reserved Wallet</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">GL Reserved</th>
                                <th class="py-3 px-3 font-semibold text-right text-gray-700 dark:text-gray-300">Net Variance</th>
                                <th class="py-3 px-3 font-semibold text-center text-gray-700 dark:text-gray-300">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($sellersData['items'] as $item)
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/40">
                                    <td class="py-3 px-3">
                                        <div class="font-medium text-gray-900 dark:text-white">
                                            {{ $item['seller_name'] }}
                                        </div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ $item['seller_email'] }} (ID: {{ $item['seller_id'] }})
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['escrow_wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['escrow_ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['reserved_wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['reserved_ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono font-semibold {{ $item['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                        ৳{{ number_format($item['net_discrepancy'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-center">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $item['is_reconciled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                                            @if ($item['is_reconciled'])
                                                <x-heroicon-m-check class="h-3 w-3" /> Reconciled
                                            @else
                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3" /> Discrepancy
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
