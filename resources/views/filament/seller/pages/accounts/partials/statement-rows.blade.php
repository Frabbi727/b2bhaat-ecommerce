{{-- One section of a statement: a heading row, its accounts and a total. Expects $title, $rows, $total, $totalLabel and optional $negate. --}}
@php($negate ??= false)

<tbody class="divide-y divide-gray-100 dark:divide-white/5">
    <tr class="bg-gray-50 dark:bg-white/5">
        <th colspan="2" class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $title }}</th>
    </tr>
    @forelse ($rows as $row)
        <tr>
            <td class="px-4 py-2 ps-6 text-gray-700 dark:text-gray-300">
                <span class="me-2 font-mono text-xs text-gray-400">{{ $row['code'] }}</span>{{ $row['name'] }}
            </td>
            <td @class(['px-4 py-2 text-end font-mono tabular-nums', 'text-danger-600 dark:text-danger-400' => ($negate ? -$row['amount'] : $row['amount']) < 0, 'text-gray-950 dark:text-white' => ($negate ? -$row['amount'] : $row['amount']) >= 0])>
                {{ $this->money($negate ? -$row['amount'] : $row['amount']) }}
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="2" class="px-4 py-2 ps-6 text-sm text-gray-400">Nothing in this period</td>
        </tr>
    @endforelse
    <tr class="font-semibold">
        <td class="px-4 py-2 text-gray-950 dark:text-white">{{ $totalLabel }}</td>
        <td class="border-t border-gray-300 px-4 py-2 text-end font-mono tabular-nums text-gray-950 dark:border-white/20 dark:text-white">{{ $this->money($total) }}</td>
    </tr>
</tbody>
