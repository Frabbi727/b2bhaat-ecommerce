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
        {{-- Executive Audit Header Bar --}}
        <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3.5">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $allReconciled ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400' }}">
                        <x-heroicon-o-shield-check class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                                Marketplace General Ledger & Sub-Ledger Parity
                            </h3>
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $allReconciled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $allReconciled ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse' }}"></span>
                                {{ $allReconciled ? '100% Reconciled' : 'Discrepancy Detected' }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            @if ($this->lastAuditedAt)
                                Last verified: <span class="font-medium text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::parse($this->lastAuditedAt)->diffForHumans() }}</span> ({{ $this->lastAuditedAt }})
                            @else
                                Audit not yet run
                            @endif
                            · Comparing live relational tables with immutable double-entry journal lines.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        wire:click="runAudit"
                        icon="heroicon-o-arrow-path"
                        color="primary"
                        size="sm"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="runAudit">Run Full Audit</span>
                        <span wire:loading wire:target="runAudit">Auditing Ledgers...</span>
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- 4 Core Reconciliation Metric Cards Grid --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Seller Wallet Payables --}}
            @php $m1 = $metrics['seller_wallet']; @endphp
            <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $m1['title'] }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $m1['is_reconciled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                            @if ($m1['is_reconciled'])
                                <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                            @else
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                            @endif
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                        GL Code 2010 · Vendor liquid payables
                    </p>
                </div>

                <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
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
            <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $m2['title'] }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $m2['is_reconciled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                            @if ($m2['is_reconciled'])
                                <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                            @else
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                            @endif
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                        GL Code 2020 · 48h escrow protection
                    </p>
                </div>

                <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
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
            <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $m3['title'] }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $m3['is_reconciled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                            @if ($m3['is_reconciled'])
                                <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                            @else
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                            @endif
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                        GL Code 2030 · Pending disbursements
                    </p>
                </div>

                <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
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
            <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $m4['title'] }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $m4['is_reconciled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                            @if ($m4['is_reconciled'])
                                <x-heroicon-m-check class="h-3.5 w-3.5" /> Reconciled
                            @else
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Discrepancy
                            @endif
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                        GL Code 2040 · Pending refund payouts
                    </p>
                </div>

                <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
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
        <div class="rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 overflow-hidden">
            {{-- Toolbar Header --}}
            <div class="border-b border-gray-100 p-5 dark:border-gray-800">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                            Seller Sub-Ledger Verification
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Individual seller reconciliation comparing wallet balances with seller-dimensioned general ledger liability accounts.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        {{-- Search Input --}}
                        <div class="w-64">
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input
                                    type="text"
                                    placeholder="Search seller name or ID..."
                                    wire:model.live.debounce.300ms="search"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        {{-- Sort Dropdown --}}
                        <div class="w-48">
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="sortBy">
                                    <option value="discrepancy_desc">Discrepancy (High to Low)</option>
                                    <option value="name_asc">Seller Name (A to Z)</option>
                                    <option value="wallet_desc">Wallet Balance (Highest)</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        {{-- Show Only Discrepancies Toggle --}}
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                            <input
                                type="checkbox"
                                wire:model.live="showOnlyDiscrepancies"
                                class="rounded border-gray-300 dark:border-gray-700 text-primary-600 shadow-sm focus:ring-primary-500"
                            />
                            <span>Show Only Discrepancies</span>
                        </label>

                        {{-- Stats Badge --}}
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                            Total: <strong class="text-gray-900 dark:text-white">{{ $sellersData['total_sellers'] }}</strong>
                            (<span class="text-emerald-600 dark:text-emerald-400">{{ $sellersData['reconciled_count'] }} OK</span>,
                            <span class="{{ $sellersData['unreconciled_count'] > 0 ? 'text-rose-600 dark:text-rose-400 font-bold' : '' }}">{{ $sellersData['unreconciled_count'] }} Diff</span>)
                        </span>
                    </div>
                </div>
            </div>

            {{-- Table Content --}}
            @if (count($sellersData['items']) === 0)
                <div class="py-12">
                    <x-filament::empty-state
                        icon="heroicon-o-shield-check"
                        heading="All Seller Sub-Ledgers In Sync"
                        description="Every verified seller wallet balance matches its general ledger liability entries with zero variance."
                    />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs divide-y divide-gray-200 dark:divide-gray-800">
                        <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
                            <tr>
                                <th class="py-3 pl-5 pr-3">Seller Details</th>
                                <th class="py-3 px-3 text-right">Wallet Avail</th>
                                <th class="py-3 px-3 text-right">GL Avail</th>
                                <th class="py-3 px-3 text-right">Escrow Wallet</th>
                                <th class="py-3 px-3 text-right">GL Escrow</th>
                                <th class="py-3 px-3 text-right">Reserved Wallet</th>
                                <th class="py-3 px-3 text-right">GL Reserved</th>
                                <th class="py-3 px-3 text-right font-bold">Net Variance</th>
                                <th class="py-3 pl-3 pr-5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                            @foreach ($sellersData['items'] as $item)
                                <tr class="hover:bg-gray-50/70 dark:hover:bg-gray-800/40 transition-colors">
                                    <td class="py-3 pl-5 pr-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[11px] font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                {{ mb_substr($item['seller_name'], 0, 1) }}
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-900 dark:text-white">
                                                    {{ $item['seller_name'] }}
                                                </div>
                                                <div class="text-[11px] text-gray-500 dark:text-gray-400 font-mono">
                                                    {{ $item['seller_email'] }} · ID #{{ $item['seller_id'] }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['escrow_wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['escrow_ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['reserved_wallet_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums text-gray-800 dark:text-gray-200">
                                        ৳{{ number_format($item['reserved_ledger_balance'], 2) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono tabular-nums font-bold {{ $item['is_reconciled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400 font-semibold' }}">
                                        ৳{{ number_format($item['net_discrepancy'], 2) }}
                                    </td>
                                    <td class="py-3 pl-3 pr-5 text-center">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $item['is_reconciled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
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
        </div>
    </div>
</x-filament-panels::page>
