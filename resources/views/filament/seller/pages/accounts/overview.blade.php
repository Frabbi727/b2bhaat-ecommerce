<x-filament-panels::page>
    @php
        $data = $this->getOverview();
        $month = $data['month'];
        $year = $data['year'];
        $position = $data['position'];
        $check = $data['reconciliation'];
        $peak = max(1, ...array_map(fn (array $m): float => abs($m['profit']), $data['months']));
        $tiles = [
            ['label' => 'Cash & bank', 'value' => $position['cash_and_bank'], 'hint' => 'Money you hold yourself'],
            ['label' => 'With B2Bhaat', 'value' => $position['receivable_from_b2bhaat'], 'hint' => 'On hold, available and payouts on the way'],
            ['label' => 'Stock at cost', 'value' => $position['inventory'], 'hint' => 'Goods not yet sold'],
            ['label' => 'Money invested', 'value' => $position['capital_invested'], 'hint' => 'Withdrawn so far: '.$this->money($position['withdrawn'])],
        ];
    @endphp

    <div class="space-y-6">
        @unless ($check['is_consistent'])
            <div class="flex gap-3 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                <p>Your books are catching up with your B2Bhaat wallet (on hold {{ $this->money($check['on_hold_books']) }} vs {{ $this->money($check['on_hold_wallet']) }}, available {{ $this->money($check['available_books']) }} vs {{ $this->money($check['available_wallet']) }}). If this does not clear within a day, contact B2Bhaat support.</p>
            </div>
        @endunless

        @unless ($data['has_own_entries'])
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-200">
                <p class="font-semibold">Start with your opening balances</p>
                <p class="mt-1">Use <strong>Record → Opening balances</strong> to enter the cash, bank money and stock you already have, then record investments, expenses and stock purchases as they happen. Your B2Bhaat sales are already here.</p>
            </div>
        @endunless

        {{-- Profit --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach ([['This month', $month], ['This year', $year]] as [$label, $pnl])
                <div @class([
                    'rounded-xl border p-4 shadow-sm',
                    'border-success-200 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' => $pnl['is_profit'],
                    'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' => ! $pnl['is_profit'],
                ])>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400">{{ $label }} · {{ $pnl['is_profit'] ? 'profit' : 'loss' }}</p>
                    <p @class(['mt-1 font-mono text-3xl font-semibold tabular-nums', 'text-success-800 dark:text-success-300' => $pnl['is_profit'], 'text-danger-800 dark:text-danger-300' => ! $pnl['is_profit']])>
                        {{ $this->money($pnl['net_profit']) }}
                    </p>
                    <dl class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                        <div class="flex justify-between"><dt>Net sales</dt><dd class="font-mono tabular-nums">{{ $this->money($pnl['net_sales']) }}</dd></div>
                        <div class="flex justify-between"><dt>Cost of goods</dt><dd class="font-mono tabular-nums">{{ $this->money(-$pnl['cost_of_goods_sold']) }}</dd></div>
                        <div class="flex justify-between"><dt>Expenses &amp; fees</dt><dd class="font-mono tabular-nums">{{ $this->money(-$pnl['total_expenses']) }}</dd></div>
                    </dl>
                </div>
            @endforeach

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Profit, last 6 months</p>
                <div class="mt-3 flex h-28 items-end gap-2" role="img" aria-label="Monthly profit for the last six months">
                    @foreach ($data['months'] as $m)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $m['label'] }}: {{ $this->money($m['profit']) }}">
                            <div
                                @class(['w-full rounded-t', 'bg-success-500' => $m['profit'] >= 0, 'bg-danger-500' => $m['profit'] < 0])
                                style="height: {{ max(2, round(abs($m['profit']) / $peak * 100)) }}%"
                            ></div>
                            <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ $m['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Position --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</p>
                    <p @class(['mt-1 font-mono text-xl font-semibold tabular-nums', 'text-danger-600 dark:text-danger-400' => $tile['value'] < 0, 'text-gray-950 dark:text-white' => $tile['value'] >= 0])>{{ $this->money($tile['value']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Net worth --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Business net worth</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->money($position['net_worth']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Everything the business owns minus what it owes{{ $position['supplier_dues'] > 0 ? ' (including '.$this->money($position['supplier_dues']).' to suppliers)' : '' }}.</p>
                <div class="mt-4 flex items-center gap-2 text-xs">
                    @if ($check['is_consistent'])
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">Matches your B2Bhaat wallet</x-filament::badge>
                    @else
                        <x-filament::badge color="warning" icon="heroicon-m-arrow-path">Updating</x-filament::badge>
                    @endif
                </div>
            </div>

            {{-- Recent entries --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm lg:col-span-2 dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Latest entries</h2>
                    <x-filament::link :href="\App\Filament\Seller\Resources\SellerJournalEntries\SellerJournalEntryResource::getUrl()" size="sm">See all</x-filament::link>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->getRecentEntries() as $entry)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-gray-950 dark:text-white">{{ $entry->description }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->entry_date->format('d M Y') }} · {{ $entry->source_type->label() }}</p>
                            </div>
                            <span class="shrink-0 font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money((float) $entry->total_amount) }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-gray-400">No entries yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-filament-panels::page>
