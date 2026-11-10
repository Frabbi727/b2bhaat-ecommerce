<x-filament-panels::page>
    @php
        $data = $this->getStatementData();
        $service = app(\App\Services\Accounting\FinancialStatementService::class);
        $money = fn (float $amount): string => $service->formatMoney($amount);
        $signed = fn (float $amount): string => $service->formatMoney($amount, signed: true);
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
                <h2 class="text-base font-semibold text-gray-800">Cash Flow Statement</h2>
            </div>
            <div class="text-right text-xs text-gray-600">
                <p>Period: {{ $data['period_label'] }}</p>
                <p>Printed: {{ now(config('app.display_timezone'))->format('d M Y, g:i A') }}</p>
                <p>Currency: BDT (৳)</p>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Summary --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
            @foreach ([
                ['label' => 'Opening cash and bank', 'value' => $money($data['opening_cash'])],
                ['label' => 'Net from operating', 'value' => $signed($data['operating']['net'])],
                ['label' => 'Net from financing', 'value' => $signed($data['financing']['net'])],
                ['label' => 'Closing cash and bank', 'value' => $money($data['closing_cash'])],
            ] as $card)
                <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                    <div class="mt-2 text-2xl font-bold font-mono tracking-tight text-gray-900 dark:text-white">{{ $card['value'] }}</div>
                </div>
            @endforeach
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

        {{-- Cash Flow Table --}}
        <div class="rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 overflow-hidden print:border print:shadow-none">
            <div class="border-b border-gray-200 bg-gray-50/75 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Cash Flow Statement</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Cash in hand and bank for {{ $data['period_label'] }} · Money still held by payment gateways or couriers is not counted until it reaches the bank · All amounts in BDT (৳)
                    </p>
                </div>
                @unless ($data['is_consistent'])
                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">
                        Opening plus movements does not equal closing cash
                    </span>
                @endunless
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-100/60 text-xs font-medium uppercase tracking-wider text-gray-600 dark:border-gray-800 dark:bg-gray-800/80 dark:text-gray-300">
                            <th scope="col" class="py-3 px-6">Activity</th>
                            <th scope="col" class="py-3 px-6 text-right w-40">Money in</th>
                            <th scope="col" class="py-3 px-6 text-right w-40">Money out</th>
                            <th scope="col" class="py-3 px-6 text-right w-40">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                        <tr class="font-semibold">
                            <td class="py-3.5 px-6 text-xs uppercase tracking-wider text-gray-700 dark:text-gray-300" colspan="3">Opening cash and bank</td>
                            <td class="py-3.5 px-6 text-right font-mono text-gray-900 dark:text-white">{{ $money($data['opening_cash']) }}</td>
                        </tr>
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="4" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                Operating activities
                            </td>
                        </tr>
                        @forelse ($data['operating']['lines'] as $line)
                            <tr wire:key="operating-{{ $line['event'] }}" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-3 px-6 text-gray-900 dark:text-white font-medium">{{ $line['label'] }}</td>
                                <td class="py-3 px-6 text-right font-mono text-gray-700 dark:text-gray-300">{{ $money($line['inflows']) }}</td>
                                <td class="py-3 px-6 text-right font-mono text-gray-700 dark:text-gray-300">{{ $line['outflows'] > 0 ? '('.$money($line['outflows']).')' : $money(0) }}</td>
                                <td class="py-3 px-6 text-right font-mono font-medium text-gray-900 dark:text-white">{{ $signed($line['net']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 px-6 text-center text-xs text-gray-400">No cash moved for these activities in this period.</td>
                            </tr>
                        @endforelse
                        <tr class="border-t-2 border-gray-200 dark:border-gray-700 font-semibold">
                            <td class="py-3.5 px-6 text-xs uppercase tracking-wider text-gray-700 dark:text-gray-300" colspan="3">Net cash from operating activities</td>
                            <td class="py-3.5 px-6 text-right font-mono font-bold text-gray-900 dark:text-white">{{ $signed($data['operating']['net']) }}</td>
                        </tr>
                        <tr class="bg-gray-50/50 dark:bg-gray-800/25">
                            <td colspan="4" class="py-2.5 px-6 text-xs font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">
                                Financing activities
                            </td>
                        </tr>
                        @forelse ($data['financing']['lines'] as $line)
                            <tr wire:key="financing-{{ $line['event'] }}" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-3 px-6 text-gray-900 dark:text-white font-medium">{{ $line['label'] }}</td>
                                <td class="py-3 px-6 text-right font-mono text-gray-700 dark:text-gray-300">{{ $money($line['inflows']) }}</td>
                                <td class="py-3 px-6 text-right font-mono text-gray-700 dark:text-gray-300">{{ $line['outflows'] > 0 ? '('.$money($line['outflows']).')' : $money(0) }}</td>
                                <td class="py-3 px-6 text-right font-mono font-medium text-gray-900 dark:text-white">{{ $signed($line['net']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 px-6 text-center text-xs text-gray-400">No cash moved for these activities in this period.</td>
                            </tr>
                        @endforelse
                        <tr class="border-t-2 border-gray-200 dark:border-gray-700 font-semibold">
                            <td class="py-3.5 px-6 text-xs uppercase tracking-wider text-gray-700 dark:text-gray-300" colspan="3">Net cash from financing activities</td>
                            <td class="py-3.5 px-6 text-right font-mono font-bold text-gray-900 dark:text-white">{{ $signed($data['financing']['net']) }}</td>
                        </tr>
                        <tr class="border-t-2 border-gray-200 dark:border-gray-700 font-semibold">
                            <td class="py-3.5 px-6 text-xs uppercase tracking-wider text-gray-700 dark:text-gray-300" colspan="3">Net change in cash</td>
                            <td class="py-3.5 px-6 text-right font-mono font-bold text-gray-900 dark:text-white">{{ $signed($data['net_change']) }}</td>
                        </tr>
                        <tr class="border-t-4 border-primary-500 bg-primary-50/40 dark:bg-primary-950/20 font-bold">
                            <td class="py-4 px-6 text-xs uppercase tracking-wider text-gray-900 dark:text-white" colspan="3">Closing cash and bank</td>
                            <td class="py-4 px-6 text-right font-mono text-base text-gray-900 dark:text-white">{{ $money($data['closing_cash']) }}</td>
                        </tr>
                    </tbody>
                </table>
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
