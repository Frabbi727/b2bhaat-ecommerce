<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Top Grid: Vacation Mode & Store Credentials -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Left: Vacation Mode Control Card (8 cols) -->
            <div class="lg:col-span-8">
                <form wire:submit="save">
                    <x-filament::section
                        heading="Store Operations & Vacation Mode"
                        description="Temporarily pause incoming retail and wholesale orders during factory maintenance, public holidays, or stocktaking."
                        icon="heroicon-o-clock"
                    >
                        <x-slot name="headerEnd">
                            @if ($is_on_vacation)
                                <x-filament::badge color="warning" icon="heroicon-m-sun">
                                    Vacation Active (Orders Paused)
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="success" icon="heroicon-m-check-circle">
                                    Store Open & Accepting Orders
                                </x-filament::badge>
                            @endif
                        </x-slot>

                        <div class="space-y-6">
                            <!-- Interactive Vacation Mode Toggle -->
                            <div class="p-4 rounded-xl border transition-colors {{ $is_on_vacation ? 'bg-amber-500/10 border-amber-500/30' : 'bg-gray-50 dark:bg-gray-800/50 border-gray-200 dark:border-gray-700' }}">
                                <label for="is_on_vacation" class="flex items-start gap-3 cursor-pointer select-none">
                                    <input 
                                        type="checkbox" 
                                        id="is_on_vacation" 
                                        wire:model.live="is_on_vacation" 
                                        class="mt-1 h-5 w-5 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 cursor-pointer"
                                    />
                                    <div class="space-y-1">
                                        <span class="font-bold text-sm text-gray-950 dark:text-white block">
                                            Enable Factory Vacation Mode
                                        </span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            When enabled, a public notice banner appears across your factory storefront and product pages. Add-to-cart and checkout buttons for your items are temporarily suspended until disabled.
                                        </p>
                                    </div>
                                </label>
                            </div>

                            <!-- Public Vacation Announcement Message -->
                            <div class="space-y-2">
                                <label for="vacation_message" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                                    Public Notice Message for Buyers
                                </label>
                                <textarea 
                                    id="vacation_message"
                                    wire:model="vacation_message" 
                                    rows="3" 
                                    placeholder="e.g. Factory closed for annual inventory stocktaking and Eid holidays. All order fulfillments will resume on Monday."
                                    class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-950 dark:text-white p-3 shadow-xs focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                ></textarea>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    This notice will be displayed to all buyers visiting your factory profile and products.
                                </p>
                            </div>

                            <!-- Expected Reopening Date -->
                            <div class="space-y-2">
                                <label for="vacation_ends_at" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                                    Expected Reopening Date (Optional)
                                </label>
                                <div class="max-w-xs">
                                    <input 
                                        type="date" 
                                        id="vacation_ends_at"
                                        wire:model="vacation_ends_at" 
                                        class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-950 dark:text-white p-2.5 shadow-xs focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                    />
                                </div>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    Buyers will see this estimated date on your store banner.
                                </p>
                            </div>
                        </div>

                        <x-slot name="footer">
                            <div class="flex items-center justify-end">
                                <x-filament::button 
                                    type="submit" 
                                    color="primary"
                                    icon="heroicon-m-check"
                                    wire:target="save"
                                >
                                    Save Store Settings
                                </x-filament::button>
                            </div>
                        </x-slot>
                    </x-filament::section>
                </form>
            </div>

            <!-- Right: Factory Credentials & Verification Card (4 cols) -->
            <div class="lg:col-span-4">
                <x-filament::section
                    heading="Factory Credentials"
                    description="Verified Trade & KYC Records"
                    icon="heroicon-o-shield-check"
                >
                    <x-slot name="headerEnd">
                        <x-filament::badge color="success">
                            Verified
                        </x-filament::badge>
                    </x-slot>

                    <div class="space-y-4 text-xs">
                        <div class="pb-3 border-b border-gray-100 dark:border-gray-800">
                            <span class="text-gray-400 block font-medium">Store & Brand Name:</span>
                            <span class="font-bold text-gray-900 dark:text-white text-base">{{ $store_name }}</span>
                        </div>

                        <div class="pb-3 border-b border-gray-100 dark:border-gray-800">
                            <span class="text-gray-400 block font-medium">Govt. Trade License:</span>
                            <span class="font-mono font-bold text-gray-800 dark:text-gray-200">
                                {{ $trade_license_number ?: 'Verified on File' }}
                            </span>
                        </div>

                        <div class="pb-3 border-b border-gray-100 dark:border-gray-800">
                            <span class="text-gray-400 block font-medium">Taxpayer Identification (TIN):</span>
                            <span class="font-mono font-bold text-gray-800 dark:text-gray-200">
                                {{ $tax_id_number ?: 'Verified on File' }}
                            </span>
                        </div>

                        <div class="pb-3 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
                            <div>
                                <span class="text-gray-400 block font-medium">Platform Commission:</span>
                                <span class="font-bold text-primary-600">{{ $commission_rate }}% Standard</span>
                            </div>
                            <x-filament::badge color="info">Tier A</x-filament::badge>
                        </div>

                        <div>
                            <span class="text-gray-400 block font-medium">Representative Contact:</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $seller_phone }}</span>
                            <span class="text-gray-400 block text-[11px]">{{ $seller_email }}</span>
                        </div>

                        @if ($store_slug)
                            <div class="pt-3">
                                <x-filament::button
                                    tag="a"
                                    href="{{ route('store.show', $store_slug) }}"
                                    target="_blank"
                                    color="gray"
                                    icon="heroicon-m-arrow-top-right-on-square"
                                    class="w-full justify-center"
                                >
                                    View Public Storefront
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            </div>
        </div>

        <!-- Bottom: Automated Low Stock Replenishment Warnings -->
        <div>
            <x-filament::section
                heading="Low Stock Warnings (Stock ≤ 10 units)"
                description="Real-time warning list for catalog variations requiring factory replenishment"
                icon="heroicon-o-exclamation-triangle"
                icon-color="warning"
            >
                <x-slot name="headerEnd">
                    <x-filament::badge color="{{ $this->lowStockVariations->isNotEmpty() ? 'warning' : 'success' }}">
                        {{ $this->lowStockVariations->count() }} Variation(s) Low
                    </x-filament::badge>
                </x-slot>

                @if ($this->lowStockVariations->isEmpty())
                    <div class="text-center py-10">
                        <div class="w-12 h-12 mx-auto rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center mb-3">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h4 class="font-bold text-sm text-gray-900 dark:text-white">All Stock Levels Healthy</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto mt-1">
                            No active product variations are currently at or below 10 units. Your inventory buffer is in optimal condition.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto -mx-6">
                        <table class="w-full text-left text-xs divide-y divide-gray-200 dark:divide-gray-800">
                            <thead class="bg-gray-50/50 dark:bg-gray-800/40 text-gray-500 uppercase tracking-wider text-[11px] font-semibold">
                                <tr>
                                    <th class="py-3 px-6">Product Title</th>
                                    <th class="py-3 px-4">Variation SKU</th>
                                    <th class="py-3 px-4">Attributes</th>
                                    <th class="py-3 px-4 text-right">Units Remaining</th>
                                    <th class="py-3 px-6 text-right">Quick Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($this->lowStockVariations as $variation)
                                    @php
                                        $attrs = $variation->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                                    @endphp
                                    <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/30 transition-colors">
                                        <td class="py-3.5 px-6 font-semibold text-gray-900 dark:text-white max-w-xs truncate">
                                            {{ $variation->product->title }}
                                        </td>
                                        <td class="py-3.5 px-4 font-mono font-medium text-gray-600 dark:text-gray-400">
                                            {{ $variation->sku }}
                                        </td>
                                        <td class="py-3.5 px-4 text-gray-500 dark:text-gray-400">
                                            {{ $attrs ?: 'Standard' }}
                                        </td>
                                        <td class="py-3.5 px-4 text-right">
                                            @if ($variation->stock_quantity <= 0)
                                                <x-filament::badge color="danger">
                                                    0 (Out of Stock)
                                                </x-filament::badge>
                                            @else
                                                <x-filament::badge color="warning">
                                                    {{ $variation->stock_quantity }} units left
                                                </x-filament::badge>
                                            @endif
                                        </td>
                                        <td class="py-3.5 px-6 text-right">
                                            <x-filament::button
                                                tag="a"
                                                size="xs"
                                                color="gray"
                                                href="{{ route('filament.seller.resources.products.edit', $variation->product_id) }}"
                                                icon="heroicon-m-arrow-right"
                                                icon-position="after"
                                            >
                                                Update Stock
                                            </x-filament::button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
