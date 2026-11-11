<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $topProducts = $this->getTopProducts();
        $viewedNotSelling = $this->getViewedNotSelling();
        $notSoldRecently = $this->getNotSoldRecently();
        $buyers = $this->getBuyerSummary();
        $bestCustomers = $this->getBestCustomers();
        $split = $this->getChannelSplit();
        $viewsNote = $this->viewsNote();

        $tiles = [
            ['label' => 'Revenue', 'value' => $this->money($summary['revenue']['value']), 'hint' => 'before returns', 'change' => $summary['revenue']['change']],
            ['label' => 'Orders', 'value' => number_format($summary['orders']['value']), 'hint' => null, 'change' => $summary['orders']['change']],
            ['label' => 'Average order value', 'value' => $this->money($summary['average_order_value']['value']), 'hint' => null, 'change' => $summary['average_order_value']['change']],
            ['label' => 'Units sold', 'value' => number_format($summary['units']['value']), 'hint' => null, 'change' => $summary['units']['change']],
        ];

        $card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900';
        $th = 'px-4 py-2 font-medium';
        $num = 'px-4 py-2 text-end font-mono tabular-nums';
    @endphp

    <div class="space-y-6">
        @include('filament.seller.pages.accounts.partials.period-picker')

        {{-- Summary --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <div class="{{ $card }} p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</p>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $tile['value'] }}</p>
                    @if ($tile['hint'])
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $tile['hint'] }}</p>
                    @endif
                    @if ($tile['change'] !== null)
                        <p @class(['mt-1 text-xs font-medium', 'text-success-700 dark:text-success-400' => $tile['change'] >= 0, 'text-danger-600 dark:text-danger-400' => $tile['change'] < 0])>
                            {{ $tile['change'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($tile['change']), 1) }}% vs previous period
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Top products --}}
        <section class="{{ $card }} overflow-hidden">
            <h2 class="px-4 pt-4 text-base font-semibold text-gray-950 dark:text-white">Top products</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="{{ $th }} text-start">Product</th>
                            <th class="{{ $th }} text-end">Units</th>
                            <th class="{{ $th }} text-end">Revenue</th>
                            <th class="{{ $th }} hidden text-end md:table-cell">Retail</th>
                            <th class="{{ $th }} hidden text-end md:table-cell">Wholesale</th>
                            <th class="{{ $th }} text-end">Views</th>
                            <th class="{{ $th }} text-end">Conversion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($topProducts as $row)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ $this->productUrl($row['product_id']) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['title'] }}</a>
                                </td>
                                <td class="{{ $num }} text-gray-950 dark:text-white">{{ number_format($row['units']) }}</td>
                                <td class="{{ $num }} font-semibold text-gray-950 dark:text-white">{{ $this->money($row['revenue']) }}</td>
                                <td class="{{ $num }} hidden text-gray-600 md:table-cell dark:text-gray-400">{{ $this->money($row['retail_revenue']) }}</td>
                                <td class="{{ $num }} hidden text-gray-600 md:table-cell dark:text-gray-400">{{ $this->money($row['wholesale_revenue']) }}</td>
                                <td class="{{ $num }} text-gray-600 dark:text-gray-400">{{ number_format($row['views']) }}</td>
                                <td class="{{ $num }} text-gray-950 dark:text-white">{{ $row['conversion'] !== null ? number_format($row['conversion'], 1).'%' : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-gray-400">No sales in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                Conversion is orders containing the product ÷ times its page was opened.
                @if ($viewsNote) {{ $viewsNote }} @endif
            </p>
        </section>

        {{-- Needs attention --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="{{ $card }} p-4">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Viewed but not selling</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">20+ views and under 1% conversion in this period. Check price, photos or MOQ.</p>
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @forelse ($viewedNotSelling as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ $this->productUrl($row['product_id']) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['title'] }}</a>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['views']) }} views · {{ $row['orders'] }} orders · {{ number_format($row['conversion'], 1) }}%</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-400">Nothing here — good.</li>
                    @endforelse
                </ul>
            </section>

            <section class="{{ $card }} p-4">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">No sales in the last 30 days</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Live products nobody has ordered recently.</p>
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @forelse ($notSoldRecently as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ $this->productUrl($row['product_id']) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['title'] }}</a>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $row['last_sold_on'] !== null ? 'Last sold: '.$this->day($row['last_sold_on']) : 'Never sold' }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-400">Nothing here — good.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Repeat buyers --}}
        <section class="{{ $card }} overflow-hidden">
            <h2 class="px-4 pt-4 text-base font-semibold text-gray-950 dark:text-white">Repeat buyers</h2>
            <div class="grid grid-cols-3 gap-4 px-4 pt-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unique buyers</p>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($buyers['unique']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Repeat buyers</p>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($buyers['repeat']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Repeat rate</p>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $buyers['repeat_rate'] !== null ? number_format($buyers['repeat_rate'], 1).'%' : '—' }}</p>
                </div>
            </div>
            <p class="px-4 pt-1 text-xs text-gray-500 dark:text-gray-400">A repeat buyer has ordered from you at least twice, including before this period.</p>

            <h3 class="px-4 pt-4 text-sm font-semibold text-gray-950 dark:text-white">Best customers</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="{{ $th }} text-start">Customer</th>
                            <th class="{{ $th }} text-start">Type</th>
                            <th class="{{ $th }} text-end">Orders</th>
                            <th class="{{ $th }} text-end">Spend</th>
                            <th class="{{ $th }} hidden text-end sm:table-cell">Last order</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($bestCustomers as $row)
                            <tr>
                                <td class="px-4 py-2 text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                <td class="px-4 py-2">
                                    <x-filament::badge :color="$row['type'] === 'wholesale' ? 'info' : 'gray'">{{ ucfirst($row['type']) }}</x-filament::badge>
                                </td>
                                <td class="{{ $num }} text-gray-950 dark:text-white">{{ $row['orders'] }}</td>
                                <td class="{{ $num }} font-semibold text-gray-950 dark:text-white">{{ $this->money($row['spend']) }}</td>
                                <td class="{{ $num }} hidden text-gray-600 sm:table-cell dark:text-gray-400">{{ $this->day($row['last_order_on']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-400">No buyers in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Retail vs wholesale --}}
        <section class="space-y-3">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Retail vs wholesale</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach (['retail' => 'Retail', 'wholesale' => 'Wholesale'] as $key => $label)
                    <div class="{{ $card }} p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->money($split[$key]['revenue']) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($split[$key]['share'], 1) }}% of revenue · {{ number_format($split[$key]['orders']) }} orders</p>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-1.5 rounded-full bg-primary-500" style="width: {{ $split[$key]['share'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($split['mixed_orders'] > 0)
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $split['mixed_orders'] }} {{ \Illuminate\Support\Str::plural('order', $split['mixed_orders']) }} had both retail and wholesale items and {{ $split['mixed_orders'] === 1 ? 'is' : 'are' }} counted in each.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
