<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Vacation Mode Control Section -->
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">🏖️</span>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Store Vacation Mode</h2>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Temporarily pause incoming storefront retail and wholesale orders during factory maintenance, public holidays, or stocktaking.
                    </p>
                </div>
                <div>
                    @if ($is_on_vacation)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700">
                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                            Vacation Mode Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Store Operational & Accepting Orders
                        </span>
                    @endif
                </div>
            </div>

            <!-- Form -->
            <form wire:submit="save" class="space-y-5">
                <!-- Toggle -->
                <div class="flex items-start gap-3 p-4 rounded-xl {{ $is_on_vacation ? 'bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800' : 'bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700' }}">
                    <input 
                        type="checkbox" 
                        id="is_on_vacation" 
                        wire:model.live="is_on_vacation" 
                        class="mt-1 h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                    />
                    <label for="is_on_vacation" class="cursor-pointer select-none">
                        <span class="font-bold text-sm text-slate-900 dark:text-white block">
                            Enable Vacation Mode
                        </span>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block mt-0.5">
                            When enabled, a prominent vacation banner will appear on your storefront and product listings. "Add to Cart" and checkout for your products will be safely disabled until turned off.
                        </span>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Vacation Notice Message -->
                    <div class="space-y-1.5 md:col-span-2">
                        <label for="vacation_message" class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Public Vacation Notice for Buyers
                        </label>
                        <textarea 
                            id="vacation_message"
                            wire:model="vacation_message" 
                            rows="3" 
                            placeholder="e.g. Factory closed for Eid-ul-Adha holidays from June 15 to June 22. All factory orders will resume shipping on June 23."
                            class="w-full text-sm rounded-xl border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white p-3 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        ></textarea>
                        <p class="text-[11px] text-slate-400">
                            This message will be visible to retail customers and wholesale buyers visiting your factory catalog.
                        </p>
                    </div>

                    <!-- Reopening Date -->
                    <div class="space-y-1.5">
                        <label for="vacation_ends_at" class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Expected Reopening Date (Optional)
                        </label>
                        <input 
                            type="date" 
                            id="vacation_ends_at"
                            wire:model="vacation_ends_at" 
                            class="w-full text-sm rounded-xl border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white p-2.5 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        />
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end">
                    <button 
                        type="submit" 
                        wire:loading.attr="disabled"
                        class="px-6 py-2.5 rounded-xl font-bold text-sm bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white shadow-sm transition disabled:opacity-50 flex items-center gap-2"
                    >
                        <span wire:loading.remove>Save Vacation Settings</span>
                        <span wire:loading class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg>
                            Saving...
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Store Credentials & Low Stock Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Store Profile Snapshot -->
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 space-y-4">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <span class="text-xl">🏭</span>
                    <h3 class="font-bold text-sm text-slate-900 dark:text-white">Store Identity</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-slate-400 block font-medium">Store Name:</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200 text-sm">{{ $store_name }}</span>
                    </div>

                    <div>
                        <span class="text-slate-400 block font-medium">Trade License:</span>
                        <span class="font-mono font-semibold text-slate-700 dark:text-slate-300">{{ $trade_license_number ?? 'Not submitted' }}</span>
                    </div>

                    <div>
                        <span class="text-slate-400 block font-medium">Tax Identification (TIN):</span>
                        <span class="font-mono font-semibold text-slate-700 dark:text-slate-300">{{ $tax_id_number ?? 'Not submitted' }}</span>
                    </div>
                </div>
            </div>

            <!-- Automated Low Stock Warnings Panel -->
            <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">⚠️</span>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white">Low Stock Warnings (Stock ≤ 10 units)</h3>
                    </div>
                    <span class="text-xs font-bold text-slate-500">
                        {{ $this->lowStockVariations->count() }} variation(s) need replenishment
                    </span>
                </div>

                @if ($this->lowStockVariations->isEmpty())
                    <div class="text-center py-8 text-slate-500 text-xs">
                        <svg class="w-8 h-8 mx-auto text-emerald-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        All variations currently have healthy inventory (> 10 units).
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-400 border-b border-slate-100 dark:border-slate-800 font-semibold">
                                <tr>
                                    <th class="py-2">Product</th>
                                    <th class="py-2">Variation SKU</th>
                                    <th class="py-2">Attributes</th>
                                    <th class="py-2 text-right">Remaining Stock</th>
                                    <th class="py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($this->lowStockVariations as $variation)
                                    @php
                                        $attrs = $variation->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                                    @endphp
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="py-2.5 font-medium text-slate-900 dark:text-white max-w-xs truncate">
                                            {{ $variation->product->title }}
                                        </td>
                                        <td class="py-2.5 font-mono text-slate-600 dark:text-slate-400">
                                            {{ $variation->sku }}
                                        </td>
                                        <td class="py-2.5 text-slate-500 dark:text-slate-400">
                                            {{ $attrs ?: 'Default' }}
                                        </td>
                                        <td class="py-2.5 text-right font-bold">
                                            @if ($variation->stock_quantity <= 0)
                                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300">
                                                    0 (Out of Stock)
                                                </span>
                                            @else
                                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300">
                                                    {{ $variation->stock_quantity }} units left
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <a 
                                                href="{{ route('filament.seller.resources.products.edit', $variation->product_id) }}" 
                                                class="text-indigo-600 dark:text-indigo-400 hover:underline font-semibold"
                                            >
                                                Update Stock →
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
