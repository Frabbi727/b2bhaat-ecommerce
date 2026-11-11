<x-filament-panels::page>
    @php($report = $this->getReport())

    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900 print:hidden">
            <label class="block sm:max-w-md">
                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Account</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="account">
                        @foreach ($this->accountOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>

        @include('filament.seller.pages.accounts.partials.period-picker')

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $report['account']->code }} · {{ $report['account']->name }}</h2>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->periodLabel() }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">Date</th>
                            <th class="px-4 py-2 text-start font-medium">Details</th>
                            <th class="px-4 py-2 text-end font-medium">Debit</th>
                            <th class="px-4 py-2 text-end font-medium">Credit</th>
                            <th class="px-4 py-2 text-end font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        <tr class="bg-gray-50/60 dark:bg-white/[0.02]">
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 font-medium text-gray-700 dark:text-gray-300">Opening balance</td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['opening_balance']) }}</td>
                        </tr>
                        @forelse ($report['lines'] as $line)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-500 dark:text-gray-400">{{ \Carbon\CarbonImmutable::parse($line['date'])->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="text-gray-950 dark:text-white">{{ $line['description'] }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-mono">{{ $line['entry_number'] }}</span>@if ($line['narration']) · {{ $line['narration'] }}@endif
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $line['debit'] > 0 ? $this->money($line['debit']) : '' }}</td>
                                <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $line['credit'] > 0 ? $this->money($line['credit']) : '' }}</td>
                                <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'text-danger-600 dark:text-danger-400' => $line['balance'] < 0, 'text-gray-950 dark:text-white' => $line['balance'] >= 0])>{{ $this->money($line['balance']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-400">No movements in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                        <tr>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-gray-950 dark:text-white">Closing balance</td>
                            <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['total_debit']) }}</td>
                            <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['total_credit']) }}</td>
                            <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['closing_balance']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($report['is_truncated'])
                <p class="border-t border-gray-200 px-4 py-2 text-xs text-warning-700 dark:border-white/10 dark:text-warning-400">
                    Showing the first {{ \App\Services\SellerAccounting\SellerFinancialReportService::LEDGER_LINE_LIMIT }} movements. Choose a shorter period to see the rest.
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
