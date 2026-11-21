<x-filament-panels::page>
    @php
        $s = $this->getSummary();
        $timeline = $this->getTimeline();
        $hasMore = count($timeline) > $this->limit;
        $timeline = array_slice($timeline, 0, $this->limit);
        $taka = fn ($amount) => \App\Services\Formatting\Money::taka($amount);
    @endphp

    <div class="space-y-8">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            {{-- We owe them --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">We owe them</p>
                <p class="mt-1 font-mono text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $taka($s['owed_to_them']['total']) }}</p>
                <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm dark:border-white/5">
                    @foreach ([['On hold (48-hour check)', $s['owed_to_them']['on_hold']], ['Ready to withdraw', $s['owed_to_them']['ready']], ['Withdrawal requested', $s['owed_to_them']['requested']]] as [$label, $amount])
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                            <dd @class(['font-mono', 'text-gray-900 dark:text-gray-100' => $amount >= 0, 'text-danger-700 dark:text-danger-400' => $amount < 0])>{{ $taka($amount) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- They owe us --}}
            <div @class([
                'rounded-xl border bg-white p-4 dark:bg-gray-900',
                'border-gray-200 dark:border-white/10' => $s['they_owe_us']['total'] <= 0,
                'border-warning-300 dark:border-warning-500/40' => $s['they_owe_us']['total'] > 0,
            ])>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">They owe us</p>
                <p @class([
                    'mt-1 font-mono text-2xl font-semibold tracking-tight',
                    'text-gray-950 dark:text-white' => $s['they_owe_us']['total'] <= 0,
                    'text-warning-700 dark:text-warning-400' => $s['they_owe_us']['total'] > 0,
                ])>{{ $taka($s['they_owe_us']['total']) }}</p>
                <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm dark:border-white/5">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">Balance below zero</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $taka($s['they_owe_us']['from_balance']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">Unpaid plan, listing and other bills</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $taka($s['they_owe_us']['unpaid_bills']) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Paid to them --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Paid to them, all time</p>
                <p class="mt-1 font-mono text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $taka($s['paid_to_them']['total']) }}</p>
                <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm dark:border-white/5">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">Last payment</dt>
                        <dd class="text-right font-mono text-gray-900 dark:text-gray-100">
                            {{ $s['paid_to_them']['last_amount'] !== null ? $taka($s['paid_to_them']['last_amount']).' on '.$s['paid_to_them']['last_date'] : 'None yet' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        @unless ($s['is_consistent'])
            <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
                This seller's figures do not add up in the books. Open the reconciliation page to see what differs.
            </div>
        @endunless

        {{-- Bills --}}
        <section class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Bills not paid yet</h2>
                <a href="{{ $this->billsUrl() }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Seller billing →</a>
            </div>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                @forelse ($s['bills'] as $bill)
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-gray-100 px-4 py-2.5 text-sm last:border-0 dark:border-white/5">
                        <span class="text-gray-900 dark:text-gray-100">
                            {{ $bill['type'] }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $bill['number'] }}</span>
                            @if ($bill['in_balance'])
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Already counted in their balance below zero</span>
                            @endif
                        </span>
                        <span class="flex items-baseline gap-3">
                            @if ($bill['is_overdue'])
                                <span class="rounded-md bg-danger-50 px-1.5 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">Overdue since {{ $bill['due'] }}</span>
                            @elseif ($bill['is_waiting_check'])
                                <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300">Payment sent, waiting for our check</span>
                            @else
                                <span class="text-xs text-gray-500 dark:text-gray-400">Due {{ $bill['due'] ?? '—' }}</span>
                            @endif
                            <span class="font-mono text-gray-900 dark:text-gray-100">{{ $taka($bill['amount']) }}</span>
                        </span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No unpaid bills.</p>
                @endforelse
            </div>
        </section>

        {{-- Timeline --}}
        <section class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Money history</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Newest first. "Balance after" is on hold + ready + requested; below zero means they owe us.</p>
                </div>
                <a href="{{ $this->allEntriesUrl() }}" class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">All entries →</a>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">What happened</th>
                            <th class="px-4 py-2 text-right font-medium">Change</th>
                            <th class="px-4 py-2 text-right font-medium">Balance after</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($timeline as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">{{ $row['date'] }}</td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ $this->entryUrl($row['entry_id']) }}" class="font-medium text-gray-900 hover:text-primary-600 hover:underline dark:text-gray-100 dark:hover:text-primary-400">{{ $row['event'] }}</a>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['entry_number'] }}@if ($row['sub_order_number']) · {{ $row['sub_order_number'] }}@endif
                                    </span>
                                </td>
                                <td @class([
                                    'whitespace-nowrap px-4 py-2.5 text-right font-mono',
                                    'text-success-700 dark:text-success-400' => $row['change'] > 0,
                                    'text-danger-700 dark:text-danger-400' => $row['change'] < 0,
                                    'text-gray-500 dark:text-gray-400' => $row['change'] == 0,
                                ])>
                                    @if ($row['moved'])
                                        <span class="font-sans text-xs">moved, no change</span>
                                    @else
                                        {{ $row['change'] > 0 ? '+' : '' }}{{ $taka($row['change']) }}
                                    @endif
                                </td>
                                <td @class([
                                    'whitespace-nowrap px-4 py-2.5 text-right font-mono',
                                    'text-gray-900 dark:text-gray-100' => $row['balance_after'] >= 0,
                                    'text-warning-700 dark:text-warning-400' => $row['balance_after'] < 0,
                                ])>{{ $taka($row['balance_after']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-3 text-gray-500 dark:text-gray-400">No money movements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($hasMore)
                <button type="button" wire:click="showMore" class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10">Show older</button>
            @endif
        </section>
    </div>
</x-filament-panels::page>
