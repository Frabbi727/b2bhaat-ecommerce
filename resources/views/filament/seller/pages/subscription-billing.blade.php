<x-filament-panels::page>
    @php
        $subscription = $this->getActiveSubscription();
        $usedSlots = $this->getUsedProductSlots();
        $allowedSlots = $this->getAllowedProductSlots();
        $invoices = $this->getPendingInvoices();
        $plans = $this->getAvailablePlans();
        $walletBalance = $this->getWalletBalance();
        $monetizationStatus = auth()->user()->sellerProfile?->monetization_status ?? 'active';
    @endphp

    @if ($monetizationStatus === 'suspended')
        <div class="p-4 mb-4 rounded-xl border border-red-300 bg-red-50 text-red-900 dark:bg-red-950/40 dark:border-red-800 dark:text-red-200">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-6 h-6 text-red-600 flex-shrink-0 mt-0.5" />
                <div>
                    <h3 class="font-bold text-base">Store Privileges Suspended</h3>
                    <p class="text-sm mt-1">Your products are temporarily hidden from the marketplace due to an overdue platform invoice. Please settle your pending invoice below to immediately restore your store.</p>
                </div>
            </div>
        </div>
    @elseif ($monetizationStatus === 'grace_period')
        <div class="p-4 mb-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-clock" class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
                <div>
                    <h3 class="font-bold text-base">Account in Grace Period</h3>
                    <p class="text-sm mt-1">Your subscription payment is overdue. Please settle your payment before the grace period ends to prevent your products from being hidden.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- 1. Current Plan & Quota Card --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="p-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm flex flex-col justify-between">
            <div>
                <span class="text-xs uppercase tracking-wider text-gray-500 font-bold">Current Subscription</span>
                <h2 class="text-2xl font-black mt-1 text-gray-950 dark:text-white">
                    {{ $subscription?->plan?->name ?? 'Free Starter' }}
                </h2>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $monetizationStatus === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-red-100 text-red-800' }}">
                    ● {{ ucfirst($monetizationStatus) }}
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-500">
                Cycle ends: <strong class="text-gray-900 dark:text-gray-200">{{ $subscription?->ends_at?->format('d M, Y') ?? 'N/A' }}</strong>
            </div>
        </div>

        <div class="p-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm flex flex-col justify-between">
            <div>
                <span class="text-xs uppercase tracking-wider text-gray-500 font-bold">Listing Capacity Gauge</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-3xl font-black text-gray-950 dark:text-white">{{ $usedSlots }}</span>
                    <span class="text-gray-500 font-medium text-sm">/ {{ $allowedSlots ? $allowedSlots.' max active' : 'Unlimited' }}</span>
                </div>
                @if ($allowedSlots)
                    @php $percent = min(100, (int)(($usedSlots / $allowedSlots) * 100)); @endphp
                    <div class="w-full bg-gray-200 dark:bg-gray-800 rounded-full h-2.5 mt-3 overflow-hidden">
                        <div class="h-2.5 rounded-full {{ $percent >= 90 ? 'bg-red-500' : ($percent >= 70 ? 'bg-amber-500' : 'bg-indigo-600') }}" style="width: {{ $percent }}%"></div>
                    </div>
                @endif
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-500">
                {{ $allowedSlots ? ($allowedSlots - $usedSlots).' slots available' : 'Unlimited products allowed' }}
            </div>
        </div>

        <div class="p-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm flex flex-col justify-between">
            <div>
                <span class="text-xs uppercase tracking-wider text-gray-500 font-bold">Seller Wallet Balance</span>
                <div class="text-3xl font-black mt-1 text-emerald-600 dark:text-emerald-400">
                    ৳{{ number_format($walletBalance, 2) }}
                </div>
                <p class="text-xs text-gray-500 mt-2">Available for withdrawal or instant invoice settlement.</p>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-500">
                Auto-Renew with balance: <strong>{{ $subscription?->auto_renew ? 'Enabled' : 'Disabled' }}</strong>
            </div>
        </div>
    </div>

    {{-- 2. Pending Invoices Table --}}
    @if ($invoices->isNotEmpty())
        <div class="mt-6 p-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm">
            <h3 class="text-lg font-bold text-gray-950 dark:text-white mb-4">Unpaid Invoices</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800 text-gray-500">
                            <th class="py-2.5">Invoice #</th>
                            <th class="py-2.5">Type</th>
                            <th class="py-2.5">Due Date</th>
                            <th class="py-2.5 text-right">Amount (৳)</th>
                            <th class="py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $inv)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-3 font-semibold">{{ $inv->invoice_number }}</td>
                                <td class="py-3 capitalize">{{ str_replace('_', ' ', $inv->invoice_type) }}</td>
                                <td class="py-3">{{ $inv->due_date?->format('d M, Y') }}</td>
                                <td class="py-3 text-right font-bold">৳{{ number_format((float)$inv->total_amount, 2) }}</td>
                                <td class="py-3 text-right space-x-2">
                                    @if ($walletBalance >= (float)$inv->total_amount)
                                        <button wire:click="payWithWallet({{ $inv->id }})" wire:confirm="Deduct ৳{{ number_format((float)$inv->total_amount, 2) }} from your wallet balance to settle this invoice?" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-sm transition">
                                            Pay from Wallet
                                        </button>
                                    @else
                                        <span class="text-xs text-gray-500">Insufficient Wallet (bKash/Bank below)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 3. Available Plans / Upgrade Section --}}
    <div class="mt-8">
        <h3 class="text-xl font-bold text-gray-950 dark:text-white mb-1">Available Marketplace Plans</h3>
        <p class="text-sm text-gray-500 mb-6">Upgrade your plan to unlock more product listing slots and reduced commission rates.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            @foreach ($plans as $p)
                @php $isCurrent = ($subscription?->plan_id === $p->id); @endphp
                <div class="p-6 bg-white dark:bg-gray-900 rounded-2xl border {{ $isCurrent ? 'border-indigo-600 ring-2 ring-indigo-600/20' : 'border-gray-200 dark:border-gray-800' }} shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between">
                            <h4 class="font-bold text-lg text-gray-900 dark:text-white">{{ $p->name }}</h4>
                            @if ($p->badge_label)
                                <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 rounded">
                                    {{ $p->badge_label }}
                                </span>
                            @endif
                        </div>
                        <div class="mt-4 flex items-baseline gap-1">
                            <span class="text-3xl font-black text-gray-950 dark:text-white">৳{{ number_format((float)$p->subscription_price, 0) }}</span>
                            <span class="text-xs text-gray-500">/ {{ $p->billing_cycle_days }} days</span>
                        </div>
                        <ul class="mt-6 space-y-2.5 text-xs text-gray-600 dark:text-gray-400">
                            <li class="flex items-center gap-2">
                                <x-filament::icon icon="heroicon-o-check" class="w-4 h-4 text-emerald-600 flex-shrink-0" />
                                <span><strong>{{ $p->is_listing_limit_enabled ? $p->max_active_products.' Products' : 'Unlimited Products' }}</strong></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <x-filament::icon icon="heroicon-o-check" class="w-4 h-4 text-emerald-600 flex-shrink-0" />
                                <span>Retail Commission: <strong>{{ $p->is_commission_enabled ? $p->retail_commission_rate.'%' : '0%' }}</strong></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <x-filament::icon icon="heroicon-o-check" class="w-4 h-4 text-emerald-600 flex-shrink-0" />
                                <span>Wholesale Commission: <strong>{{ $p->is_commission_enabled ? $p->wholesale_commission_rate.'%' : '0%' }}</strong></span>
                            </li>
                        </ul>
                    </div>

                    <div class="mt-8 pt-4 border-t border-gray-100 dark:border-gray-800">
                        @if ($isCurrent)
                            <button disabled class="w-full py-2 bg-gray-100 dark:bg-gray-800 text-gray-500 text-xs font-bold rounded-lg cursor-not-allowed">
                                Current Active Plan
                            </button>
                        @else
                            <button wire:click="upgradePlan({{ $p->id }})" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg transition shadow-sm">
                                Switch to {{ $p->name }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
