@php
    use App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderInfolist;
    use App\Services\Formatting\Money;

    // An order passes its own items; a return passes only the lines coming back, with their quantity and value
    $lines ??= $getRecord()->items->map(fn ($item): array => ['item' => $item, 'quantity' => $item->quantity, 'total' => $item->total_price]);
@endphp

<div>
    {{-- Column heads only where there is room for columns --}}
    <div class="hidden grid-cols-[1fr_7rem_7.5rem_7.5rem] gap-4 border-b border-gray-200 pb-2.5 text-xs font-medium text-gray-500 sm:grid dark:border-white/10 dark:text-gray-400">
        <span>Product</span>
        <span class="text-end">Qty</span>
        <span class="text-end">Unit price</span>
        <span class="text-end">Total</span>
    </div>

    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($lines as $line)
            @php
                $item = $line['item'];
                $image = $item->product?->primaryImage?->public_url;
                $unit = $item->product?->unit?->symbol;
                $quantity = number_format($line['quantity']).($unit ? ' '.$unit : '');
                $details = SubOrderInfolist::itemDetails($item);
                $discounted = (float) $item->original_unit_price > (float) $item->unit_price;
            @endphp

            <li class="flex gap-3 py-3 last:pb-0 sm:grid sm:grid-cols-[1fr_7rem_7.5rem_7.5rem] sm:items-center sm:gap-4">
                <div class="flex min-w-0 flex-1 gap-3">
                    @if ($image)
                        <img src="{{ $image }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" />
                    @else
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
                            <x-filament::icon icon="heroicon-o-photo" class="size-5" />
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $item->product?->title ?? 'Removed product' }}</p>
                        @if ($details)
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $details }}</p>
                        @endif

                        {{-- Phone: quantity and price on one line under the name --}}
                        <div class="mt-1.5 flex items-baseline justify-between gap-3 sm:hidden">
                            <p class="text-sm text-gray-700 tabular-nums dark:text-gray-300">
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $quantity }}</span>
                                × {{ Money::taka($item->unit_price) }}
                                @if ($discounted)
                                    <span class="text-xs text-gray-400 line-through dark:text-gray-500">{{ Money::taka($item->original_unit_price) }}</span>
                                @endif
                            </p>
                            <p class="text-sm font-medium text-gray-950 tabular-nums dark:text-white">{{ Money::taka($line['total']) }}</p>
                        </div>
                    </div>
                </div>

                <p class="hidden text-end text-sm font-semibold text-gray-950 tabular-nums sm:block dark:text-white">{{ $quantity }}</p>
                <div class="hidden text-end text-sm text-gray-700 tabular-nums sm:block dark:text-gray-300">
                    {{ Money::taka($item->unit_price) }}
                    @if ($discounted)
                        <p class="text-xs text-gray-400 line-through dark:text-gray-500">{{ Money::taka($item->original_unit_price) }}</p>
                    @endif
                </div>
                <p class="hidden text-end text-sm font-medium text-gray-950 tabular-nums sm:block dark:text-white">{{ Money::taka($line['total']) }}</p>
            </li>
        @endforeach
    </ul>
</div>
