@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\StockMovement> $movements */
    $referenceLabel = function (\App\Models\StockMovement $movement): ?string {
        return match (true) {
            $movement->reference instanceof \App\Models\Order => 'Order #'.$movement->reference->order_number,
            $movement->reference instanceof \App\Models\SubOrder => 'Order #'.$movement->reference->sub_order_number,
            $movement->reference instanceof \App\Models\ReturnRequest => 'Return #'.$movement->reference->id,
            default => null,
        };
    };
@endphp

@if ($movements->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No stock changes recorded yet.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2 pe-3 font-medium">When</th>
                    @if ($hasOptions)
                        <th class="py-2 pe-3 font-medium">Option</th>
                    @endif
                    <th class="py-2 pe-3 font-medium">What happened</th>
                    <th class="py-2 pe-3 text-end font-medium">Change</th>
                    <th class="py-2 text-end font-medium">Stock after</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($movements as $movement)
                    <tr>
                        <td class="whitespace-nowrap py-2 pe-3 text-gray-500 dark:text-gray-400">
                            {{ $movement->created_at?->timezone(config('app.display_timezone'))->format('d M Y, g:i A') }}
                        </td>
                        @if ($hasOptions)
                            <td class="py-2 pe-3 text-gray-700 dark:text-gray-300">{{ $movement->variation?->sku ?? $movement->sku }}</td>
                        @endif
                        <td class="py-2 pe-3">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $movement->reasonLabel() }}</span>
                            @if ($label = $referenceLabel($movement))
                                <span class="text-gray-500 dark:text-gray-400">· {{ $label }}</span>
                            @endif
                            @if ($movement->note || $movement->user)
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ collect([$movement->note, $movement->user ? 'by '.$movement->user->name : null])->filter()->implode(' · ') }}
                                </div>
                            @endif
                        </td>
                        <td @class([
                            'whitespace-nowrap py-2 pe-3 text-end font-medium tabular-nums',
                            'text-success-600 dark:text-success-400' => $movement->quantity_change > 0,
                            'text-danger-600 dark:text-danger-400' => $movement->quantity_change < 0,
                        ])>
                            {{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }}
                        </td>
                        <td class="py-2 text-end tabular-nums text-gray-950 dark:text-white">{{ $movement->quantity_after }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($movements->count() === $shown)
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Showing the last {{ $shown }} changes.</p>
    @endif
@endif
