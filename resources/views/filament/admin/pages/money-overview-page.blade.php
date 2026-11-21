<x-filament-panels::page>
    @php
        $o = $this->getOverview();
        $f = $this->getFollowUp();
        $taka = fn ($amount) => \App\Services\Formatting\Money::taka($amount);
        $asOf = $this->asOfLabel();

        $balanceCards = [
            [
                'title' => 'Money we hold',
                'amount' => $o['money_we_hold']['total'],
                'note' => "In our accounts on {$asOf}",
                'tone' => 'neutral',
                'parts' => collect($o['money_we_hold']['parts'])->map(fn ($part, $i) => [
                    'label' => $part['label'],
                    'amount' => $part['amount'],
                    'url' => $this->entriesUrl(array_keys(\App\Services\Accounting\MoneyOverviewService::CASH_ACCOUNTS)[$i]),
                ])->all(),
                'how' => 'Cash in hand + bank + our bKash/Nagad wallet, from the books. Part of this money belongs to sellers and buyers (the next cards).',
            ],
            [
                'title' => 'We owe sellers',
                'amount' => $o['owed_to_sellers']['total'],
                'note' => $o['owed_to_sellers']['sellers'].' '.str('seller')->plural($o['owed_to_sellers']['sellers']).' in credit',
                'tone' => 'neutral',
                'parts' => [
                    ['label' => 'On hold (48-hour check after delivery)', 'amount' => $o['owed_to_sellers']['on_hold'], 'url' => $this->entriesUrl('seller_escrow_payable')],
                    ['label' => 'Ready to withdraw', 'amount' => $o['owed_to_sellers']['ready'], 'url' => $this->entriesUrl('seller_wallet_payable')],
                    ['label' => 'Withdrawal requested', 'amount' => $o['owed_to_sellers']['requested'], 'url' => $this->entriesUrl('reserved_payout_payable')],
                ],
                'how' => 'For each seller: on hold + ready + requested. Only sellers whose total is above zero are added here, so a seller who owes us never hides another seller\'s money.',
            ],
            [
                'title' => 'Sellers owe us',
                'amount' => $o['owed_by_sellers']['total'],
                'note' => $o['owed_by_sellers']['total'] > 0 ? 'Money sellers must pay us' : 'No seller owes us',
                'tone' => $o['owed_by_sellers']['total'] > 0 ? 'warning' : 'neutral',
                'parts' => [
                    ['label' => $o['owed_by_sellers']['sellers'].' '.str('seller')->plural($o['owed_by_sellers']['sellers']).' below zero (commission on cash they kept, refunds charged to them)', 'amount' => $o['owed_by_sellers']['from_balances'], 'url' => $this->entriesUrl('seller_wallet_payable')],
                    ['label' => $o['owed_by_sellers']['bills'].' unpaid plan, listing and other bills', 'amount' => $o['owed_by_sellers']['unpaid_bills'], 'url' => $this->billsUrl()],
                ],
                'how' => 'Sellers whose own total is below zero in the books, plus bills issued and not yet paid. Bills are only booked when paid, so they come from Seller billing. Commission bills for cash a seller kept are not added again: that debt is already in their balance.',
            ],
            [
                'title' => 'Refunds we owe buyers',
                'amount' => $o['refunds_owed'],
                'note' => 'Approved, not sent yet',
                'tone' => $o['refunds_owed'] > 0 ? 'warning' : 'neutral',
                'parts' => [],
                'how' => 'Customer refund payable (account 2040) in the books: refunds approved but not yet paid out.',
                'link' => ['url' => $this->entriesUrl('customer_refund_payable'), 'label' => 'See the entries'],
            ],
            [
                'title' => 'Cash couriers owe us',
                'amount' => $o['courier_cash'],
                'note' => 'Cash-on-delivery money not paid to us yet',
                'tone' => 'neutral',
                'parts' => [],
                'how' => 'Courier COD receivable (account 1040): cash couriers collected from buyers and have not yet paid to us.',
                'link' => ['url' => $this->entriesUrl('courier_cod_receivable'), 'label' => 'See the entries'],
            ],
        ];

        $hasRunningCosts = $this->hasRunningCosts();
    @endphp

    <div class="space-y-8">
        {{-- Period --}}
        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->presets() as $key => $label)
                    <button
                        type="button"
                        wire:click="setPreset('{{ $key }}')"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            'bg-primary-600 text-white' => $preset === $key,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $preset !== $key,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <label for="money-from">From</label>
                <input id="money-from" type="date" wire:model.live="startDate" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                <label for="money-to">to</label>
                <input id="money-to" type="date" wire:model.live="endDate" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            </div>
        </div>

        {{-- Balances --}}
        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Where the money is</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Balances at the end of {{ $asOf }}.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($balanceCards as $card)
                    <div @class([
                        'flex flex-col rounded-xl border bg-white p-4 dark:bg-gray-900',
                        'border-gray-200 dark:border-white/10' => $card['tone'] === 'neutral',
                        'border-warning-300 dark:border-warning-500/40' => $card['tone'] === 'warning',
                    ])>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $card['title'] }}</p>
                        <p class="mt-1 font-mono text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $taka($card['amount']) }}</p>
                        <p @class([
                            'text-xs',
                            'text-gray-500 dark:text-gray-400' => $card['tone'] === 'neutral',
                            'text-warning-700 dark:text-warning-400' => $card['tone'] === 'warning',
                        ])>{{ $card['note'] }}</p>

                        @if ($card['parts'] !== [])
                            <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm dark:border-white/5">
                                @foreach ($card['parts'] as $part)
                                    <div class="flex items-baseline justify-between gap-3">
                                        <dt class="text-gray-600 dark:text-gray-400">
                                            <a href="{{ $part['url'] }}" class="hover:text-primary-600 hover:underline dark:hover:text-primary-400">{{ $part['label'] }}</a>
                                        </dt>
                                        <dd class="shrink-0 font-mono text-gray-900 dark:text-gray-100">{{ $taka($part['amount']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        <details class="mt-auto pt-3 text-xs text-gray-500 dark:text-gray-400">
                            <summary class="cursor-pointer select-none font-medium text-primary-600 dark:text-primary-400">How is this worked out?</summary>
                            <p class="mt-1.5 leading-relaxed">{{ $card['how'] }}</p>
                            @isset($card['link'])
                                <a href="{{ $card['link']['url'] }}" class="mt-1 inline-block font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $card['link']['label'] }} →</a>
                            @endisset
                        </details>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Income and costs --}}
        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">What we earned</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->periodLabel() }}. Sales value belongs to sellers and is not counted here.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                @foreach ([['Our income', $o['income'], 'Commission, plan and listing fees and delivery charges, from the books. Commission given back on returns is already taken off.'], ['Our costs', $o['costs'], 'Discounts we paid for, payment and courier charges, and running costs recorded with "Record a cost" (hosting, SMS, staff, ads), from the books.']] as [$title, $block, $how])
                    <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $title }}</p>
                        <p class="mt-1 font-mono text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $taka($block['total']) }}</p>
                        @if ($block['parts'] !== [])
                            <dl class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm dark:border-white/5">
                                @foreach ($block['parts'] as $part)
                                    <div class="flex items-baseline justify-between gap-3">
                                        <dt class="text-gray-600 dark:text-gray-400">{{ $part['label'] }}</dt>
                                        <dd class="shrink-0 font-mono text-gray-900 dark:text-gray-100">{{ $taka($part['amount']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-500 dark:border-white/5 dark:text-gray-400">Nothing in this period.</p>
                        @endif
                        <details class="mt-auto pt-3 text-xs text-gray-500 dark:text-gray-400">
                            <summary class="cursor-pointer select-none font-medium text-primary-600 dark:text-primary-400">How is this worked out?</summary>
                            <p class="mt-1.5 leading-relaxed">{{ $how }}</p>
                            <a href="{{ $this->incomeStatementUrl() }}" class="mt-1 inline-block font-medium text-primary-600 hover:underline dark:text-primary-400">Open the income statement →</a>
                        </details>
                    </div>
                @endforeach

                <div @class([
                    'flex flex-col rounded-xl border bg-white p-4 dark:bg-gray-900',
                    'border-success-300 dark:border-success-500/40' => $o['profit'] >= 0,
                    'border-danger-300 dark:border-danger-500/40' => $o['profit'] < 0,
                ])>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $o['profit'] >= 0 ? 'Profit' : 'Loss' }}</p>
                    <p @class([
                        'mt-1 font-mono text-2xl font-semibold tracking-tight',
                        'text-success-700 dark:text-success-400' => $o['profit'] >= 0,
                        'text-danger-700 dark:text-danger-400' => $o['profit'] < 0,
                    ])>{{ $taka(abs($o['profit'])) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Income minus costs</p>
                    @unless ($hasRunningCosts)
                        <p class="mt-3 rounded-lg bg-gray-50 p-2.5 text-xs leading-relaxed text-gray-600 dark:bg-white/5 dark:text-gray-400">
                            No running costs (hosting, SMS, staff, ads) are recorded for these dates, so this is profit before running costs. Use "Record a cost" at the top to add them.
                        </p>
                    @endunless
                </div>
            </div>
        </section>

        {{-- Follow-up --}}
        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Needs follow-up</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">As of now, whatever dates are chosen above.</p>
            </div>

            @php
                $lastCheck = $f['last_check'];
                $simple = [
                    [
                        'ok' => $f['waiting_payouts']['count'] === 0,
                        'text' => $f['waiting_payouts']['count'] === 0
                            ? 'No withdrawal has waited more than '.\App\Services\Accounting\MoneyOverviewService::PAYOUT_WAIT_DAYS.' days'
                            : $f['waiting_payouts']['count'].' '.str('withdrawal')->plural($f['waiting_payouts']['count']).' waiting more than '.\App\Services\Accounting\MoneyOverviewService::PAYOUT_WAIT_DAYS.' days ('.$taka($f['waiting_payouts']['total']).', oldest '.$f['waiting_payouts']['oldest'].')',
                        'url' => $this->payoutsUrl(),
                    ],
                    [
                        'ok' => $f['pending_refunds']['count'] === 0,
                        'text' => $f['pending_refunds']['count'] === 0
                            ? 'No refund waiting to be sent'
                            : $f['pending_refunds']['count'].' '.str('refund')->plural($f['pending_refunds']['count']).' not sent to buyers ('.$taka($f['pending_refunds']['total']).', oldest '.$f['pending_refunds']['oldest'].')',
                        'url' => $this->refundsUrl(),
                    ],
                    [
                        'ok' => $f['old_courier_cash']['count'] === 0,
                        'text' => $f['old_courier_cash']['count'] === 0
                            ? 'No courier cash older than '.\App\Services\Accounting\MoneyOverviewService::COURIER_WAIT_DAYS.' days'
                            : $f['old_courier_cash']['count'].' '.str('parcel')->plural($f['old_courier_cash']['count']).' delivered more than '.\App\Services\Accounting\MoneyOverviewService::COURIER_WAIT_DAYS.' days ago, courier has not paid ('.$taka($f['old_courier_cash']['total']).', oldest '.$f['old_courier_cash']['oldest'].')',
                        'url' => $this->entriesUrl('courier_cod_receivable'),
                    ],
                    [
                        'ok' => $lastCheck !== null && $lastCheck['is_reconciled'],
                        'text' => match (true) {
                            $lastCheck === null => 'The daily money check has not run yet',
                            $lastCheck['is_reconciled'] => 'Daily money check: everything matches the books (checked '.\Carbon\Carbon::parse($lastCheck['checked_at'])->timezone(config('app.display_timezone'))->format('d M, g:i A').')',
                            default => 'Daily money check found: '.implode('; ', $lastCheck['problems']),
                        },
                        'url' => $this->reconciliationUrl(),
                    ],
                ];
            @endphp

            <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-white/5 dark:border-white/10 dark:bg-gray-900">
                @foreach ($simple as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="flex items-start gap-3 px-4 py-3 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                            @if ($item['ok'])
                                <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                            @else
                                <x-heroicon-m-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400" />
                            @endif
                            <span @class(['text-gray-600 dark:text-gray-400' => $item['ok'], 'font-medium text-gray-950 dark:text-white' => ! $item['ok']])>{{ $item['text'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ([['Sellers with overdue bills', $f['overdue_bills'], 'No seller has an overdue bill'], ['Sellers below zero', $f['sellers_owing'], 'No seller owes us from their balance']] as [$title, $rows, $empty])
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                        <p class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-950 dark:border-white/5 dark:text-white">{{ $title }}</p>
                        @forelse ($rows as $row)
                            <a href="{{ $this->sellerUrl($row['seller_id']) }}" class="flex items-baseline justify-between gap-3 border-b border-gray-100 px-4 py-2.5 text-sm last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                                <span class="text-gray-900 dark:text-gray-100">
                                    {{ $row['seller'] }}
                                    @isset($row['bills'])
                                        <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['bills'] }} {{ str('bill')->plural($row['bills']) }}, oldest due {{ $row['oldest_due'] }}</span>
                                    @endisset
                                </span>
                                <span class="shrink-0 font-mono text-warning-700 dark:text-warning-400">{{ $taka($row['total']) }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
