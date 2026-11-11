@php
    /** @var \App\Models\SellerAccounting\SellerJournalEntry $entry */
    $totalDebit = (float) $entry->lines->sum('debit');
    $totalCredit = (float) $entry->lines->sum('credit');
@endphp

<div class="space-y-4">
    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Date</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $entry->entry_date->format('d M Y') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Recorded by</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $entry->source === 'auto' ? 'B2Bhaat (automatic)' : 'You' }}</dd>
        </div>
        @if ($entry->reversalOf)
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Reverses</dt>
                <dd class="font-mono font-medium text-gray-950 dark:text-white">{{ $entry->reversalOf->entry_number }}</dd>
            </div>
        @endif
        @if ($entry->reversedBy)
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Reversed by</dt>
                <dd class="font-mono font-medium text-danger-600 dark:text-danger-400">{{ $entry->reversedBy->entry_number }}</dd>
            </div>
        @endif
    </dl>

    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $entry->description }}</p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-start font-medium">Account</th>
                    <th class="px-3 py-2 text-start font-medium">Details</th>
                    <th class="px-3 py-2 text-end font-medium">Debit</th>
                    <th class="px-3 py-2 text-end font-medium">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($entry->lines as $line)
                    <tr>
                        <td class="px-3 py-2 align-top {{ (float) $line->credit > 0 ? 'ps-8' : '' }}">
                            <span class="font-mono text-xs text-gray-400">{{ $line->account->code }}</span>
                            <span class="text-gray-950 dark:text-white">{{ $line->account->name }}</span>
                        </td>
                        <td class="px-3 py-2 align-top text-gray-500 dark:text-gray-400">
                            {{ $line->narration ?? $line->product?->title }}
                        </td>
                        <td class="px-3 py-2 text-end align-top font-mono tabular-nums text-gray-950 dark:text-white">
                            {{ (float) $line->debit > 0 ? '৳'.number_format((float) $line->debit, 2) : '' }}
                        </td>
                        <td class="px-3 py-2 text-end align-top font-mono tabular-nums text-gray-950 dark:text-white">
                            {{ (float) $line->credit > 0 ? '৳'.number_format((float) $line->credit, 2) : '' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                <tr>
                    <td class="px-3 py-2" colspan="2">Total</td>
                    <td class="px-3 py-2 text-end font-mono tabular-nums">৳{{ number_format($totalDebit, 2) }}</td>
                    <td class="px-3 py-2 text-end font-mono tabular-nums">৳{{ number_format($totalCredit, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
