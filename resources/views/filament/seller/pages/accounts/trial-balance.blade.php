<x-filament-panels::page>
    @php($report = $this->getReport())

    <div class="space-y-6">
        @include('filament.seller.pages.accounts.partials.as-of-picker')

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Account balances</h2>
                @if ($report['is_balanced'])
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">Debits equal credits</x-filament::badge>
                @else
                    <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">Out of balance</x-filament::badge>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">Account</th>
                            <th class="hidden px-4 py-2 text-start font-medium sm:table-cell">Type</th>
                            <th class="px-4 py-2 text-end font-medium">Debit</th>
                            <th class="px-4 py-2 text-end font-medium">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($report['rows'] as $row)
                            <tr>
                                <td class="px-4 py-2 text-gray-950 dark:text-white"><span class="me-2 font-mono text-xs text-gray-400">{{ $row['code'] }}</span>{{ $row['name'] }}</td>
                                <td class="hidden px-4 py-2 text-gray-500 sm:table-cell dark:text-gray-400">{{ $row['type'] }}</td>
                                <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $row['debit'] > 0 ? $this->money($row['debit']) : '' }}</td>
                                <td class="px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $row['credit'] > 0 ? $this->money($row['credit']) : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-400">No entries yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                        <tr>
                            <td class="px-4 py-3 text-gray-950 dark:text-white">Total</td>
                            <td class="hidden sm:table-cell"></td>
                            <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['total_debit']) }}</td>
                            <td class="px-4 py-3 text-end font-mono tabular-nums text-gray-950 dark:text-white">{{ $this->money($report['total_credit']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
