<x-filament-panels::page>
    @php
        $subscription = $this->getActiveSubscription();
        $usedSlots = $this->getUsedProductSlots();
        $allowedSlots = $this->getAllowedProductSlots();
        $pendingInvoices = $this->getPendingInvoices();
        $paidInvoices = $this->getPaidInvoices();
        $plans = $this->getAvailablePlans();
        $walletBalance = $this->getWalletBalance();
        $monetizationStatus = auth()->user()->sellerProfile?->monetization_status ?? 'active';

        $percentCapacity = $allowedSlots ? min(100, (int) round(($usedSlots / $allowedSlots) * 100)) : 0;
        $isOverQuota = $allowedSlots !== null && $usedSlots >= $allowedSlots;
        $daysRemaining = $subscription?->ends_at ? (int) ceil(now()->diffInDays($subscription->ends_at, false)) : null;
    @endphp

    <style>
        .b2b-sub-deck {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 768px) {
            .b2b-sub-deck {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .b2b-plan-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            margin-top: 1rem;
        }
        @media (min-width: 640px) {
            .b2b-plan-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (min-width: 1024px) {
            .b2b-plan-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        .b2b-plan-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-radius: 0.75rem;
            padding: 1.25rem;
            border: 1px solid rgba(228, 228, 231, 0.8);
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .dark .b2b-plan-card {
            background: #18181b;
            border-color: rgba(39, 39, 42, 0.9);
        }
        .b2b-plan-card:hover {
            border-color: rgba(161, 161, 170, 0.8);
        }
        .dark .b2b-plan-card:hover {
            border-color: rgba(82, 82, 91, 0.8);
        }
        .b2b-plan-card.is-current {
            border: 2px solid rgb(16, 185, 129);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }
        .b2b-progress-track {
            width: 100%;
            height: 8px;
            border-radius: 9999px;
            background: #f4f4f5;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        .dark .b2b-progress-track {
            background: #27272a;
        }
        .b2b-progress-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease;
        }
        .b2b-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .b2b-table th {
            text-align: left;
            padding: 0.625rem 1rem;
            font-weight: 600;
            color: #71717a;
            border-bottom: 1px solid #e4e4e7;
            background: rgba(244, 244, 245, 0.6);
        }
        .dark .b2b-table th {
            color: #a1a1aa;
            border-bottom-color: #27272a;
            background: rgba(24, 24, 27, 0.6);
        }
        .b2b-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f4f4f5;
            color: #27272a;
        }
        .dark .b2b-table td {
            border-bottom-color: #27272a;
            color: #e4e4e7;
        }
        .b2b-table tr:hover td {
            background: rgba(244, 244, 245, 0.3);
        }
        .dark .b2b-table tr:hover td {
            background: rgba(39, 39, 42, 0.3);
        }
    </style>

    {{-- 1. Urgent Status Alerts (Grace Period or Suspension) --}}
    @if ($monetizationStatus === 'suspended')
        <x-filament::callout
            color="danger"
            icon="heroicon-o-shield-exclamation"
            heading="Store Privileges Suspended"
            class="mb-5"
        >
            <div style="font-size: 0.8125rem; line-height: 1.5;">
                Your products are temporarily hidden from the marketplace due to an overdue billing invoice.
                Please settle your pending invoice below to immediately restore your store visibility.
                @if ($pendingInvoices->isNotEmpty())
                    <div style="margin-top: 0.5rem;">
                        <x-filament::link href="#pending-invoices-section" color="danger">
                            Jump to pending invoice ↓
                        </x-filament::link>
                    </div>
                @endif
            </div>
        </x-filament::callout>
    @elseif ($monetizationStatus === 'grace_period')
        <x-filament::callout
            color="warning"
            icon="heroicon-o-clock"
            heading="Account in Grace Period"
            class="mb-5"
        >
            <div style="font-size: 0.8125rem; line-height: 1.5;">
                Your subscription payment is overdue. Please settle your invoice before the grace period ends to prevent your products from being hidden.
            </div>
        </x-filament::callout>
    @endif

    {{-- 2. Overview Metric Deck (Operate Mode: Clean, native cards) --}}
    <div class="b2b-sub-deck">
        {{-- Card 1: Active Subscription --}}
        <x-filament::section>
            <x-slot name="heading">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <span style="font-size: 0.8125rem; font-weight: 600; color: #71717a;">Current Plan</span>
                    <x-filament::badge :color="$monetizationStatus === 'active' ? 'success' : 'danger'">
                        ● {{ ucfirst($monetizationStatus) }}
                    </x-filament::badge>
                </div>
            </x-slot>

            <div style="margin-top: 0.25rem;">
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.025em; color: inherit;">
                    {{ $subscription?->plan?->name ?? 'Free Starter' }}
                </div>
                <div style="margin-top: 0.35rem; font-size: 0.75rem; color: #71717a;">
                    @if ($subscription?->ends_at)
                        @if ($daysRemaining > 0)
                            Renews on <strong style="color: inherit;">{{ $subscription->ends_at->format('d M, Y') }}</strong> ({{ $daysRemaining }} {{ Str::plural('day', $daysRemaining) }} left)
                        @else
                            Cycle expired on <strong style="color: #e11d48;">{{ $subscription->ends_at->format('d M, Y') }}</strong>
                        @endif
                    @else
                        Continuous active tier
                    @endif
                </div>
            </div>

            <x-slot name="footer">
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem;">
                    <span style="color: #71717a;">Wallet Auto-Renewal</span>
                    <button
                        type="button"
                        wire:click="toggleAutoRenew"
                        wire:loading.attr="disabled"
                        title="Click to toggle automatic renewal"
                        style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; padding: 0.2rem 0.5rem; border-radius: 0.375rem; border: none; cursor: pointer; {{ ($subscription?->auto_renew ?? true) ? 'background: rgba(16, 185, 129, 0.12); color: #059669;' : 'background: #f4f4f5; color: #71717a;' }}"
                    >
                        <span style="width: 6px; height: 6px; border-radius: 9999px; background: {{ ($subscription?->auto_renew ?? true) ? '#10b981' : '#a1a1aa' }};"></span>
                        {{ ($subscription?->auto_renew ?? true) ? 'Enabled' : 'Paused' }}
                    </button>
                </div>
            </x-slot>
        </x-filament::section>

        {{-- Card 2: Listing Capacity Gauge --}}
        <x-filament::section>
            <x-slot name="heading">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <span style="font-size: 0.8125rem; font-weight: 600; color: #71717a;">Listing Capacity</span>
                    @if ($allowedSlots)
                        <x-filament::badge :color="$isOverQuota ? 'danger' : ($percentCapacity >= 80 ? 'warning' : 'gray')">
                            {{ $percentCapacity }}% Used
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="success">Unlimited</x-filament::badge>
                    @endif
                </div>
            </x-slot>

            <div style="margin-top: 0.25rem;">
                <div style="display: flex; align-items: baseline; gap: 0.5rem;">
                    <span style="font-size: 1.625rem; font-weight: 800; letter-spacing: -0.025em; color: inherit;">{{ number_format($usedSlots) }}</span>
                    <span style="font-size: 0.8125rem; color: #71717a;">/ {{ $allowedSlots ? number_format($allowedSlots) . ' max listings' : 'unlimited products' }}</span>
                </div>

                @if ($allowedSlots)
                    <div class="b2b-progress-track">
                        <div
                            class="b2b-progress-fill"
                            style="width: {{ $percentCapacity }}%; background: {{ $isOverQuota ? '#ef4444' : ($percentCapacity >= 80 ? '#f59e0b' : '#10b981') }};"
                        ></div>
                    </div>
                @endif
            </div>

            <x-slot name="footer">
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem;">
                    <span style="color: #71717a;">Available Quota</span>
                    @if ($allowedSlots)
                        <span style="font-weight: 600; color: {{ $isOverQuota ? '#e11d48' : 'inherit' }};">
                            {{ max(0, $allowedSlots - $usedSlots) }} slots remaining
                        </span>
                    @else
                        <span style="font-weight: 600; color: #059669;">Unlimited catalog</span>
                    @endif
                </div>
            </x-slot>
        </x-filament::section>

        {{-- Card 3: Wallet Balance & Payouts --}}
        <x-filament::section>
            <x-slot name="heading">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <span style="font-size: 0.8125rem; font-weight: 600; color: #71717a;">Seller Wallet Balance</span>
                    <x-filament::link :href="route('filament.seller.resources.seller-wallets.index')" color="primary" size="xs">
                        Wallet & payouts →
                    </x-filament::link>
                </div>
            </x-slot>

            <div style="margin-top: 0.25rem;">
                <div style="font-size: 1.625rem; font-weight: 800; font-family: monospace; letter-spacing: -0.025em; color: #059669;">
                    ৳{{ number_format($walletBalance, 2) }}
                </div>
                <div style="margin-top: 0.35rem; font-size: 0.75rem; color: #71717a;">
                    Available for instant zero-fee billing settlement or direct payout.
                </div>
            </div>

            <x-slot name="footer">
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem; color: #71717a;">
                    <span>Settlement Speed</span>
                    <span style="font-weight: 600; color: inherit;">Instant Automatic Offset</span>
                </div>
            </x-slot>
        </x-filament::section>
    </div>

    {{-- 3. Pending Invoices Section (Action Required) --}}
    @if ($pendingInvoices->isNotEmpty())
        <div id="pending-invoices-section" style="margin-bottom: 1.5rem;">
            <x-filament::section
                icon="heroicon-o-receipt-percent"
                icon-color="warning"
                heading="Action Required: Unpaid Invoices"
                :description="'You have ' . $pendingInvoices->count() . ' invoice(s) awaiting payment. Settle using your wallet to keep services active.'"
            >
                <div style="overflow-x: auto;">
                    <table class="b2b-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Description</th>
                                <th>Due Date</th>
                                <th style="text-align: right;">Amount (৳)</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingInvoices as $inv)
                                <tr>
                                    <td style="font-family: monospace; font-weight: 600;">
                                        {{ $inv->invoice_number }}
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; text-transform: capitalize;">{{ str_replace('_', ' ', $inv->invoice_type) }}</div>
                                        @if (!empty($inv->metadata['plan_name']))
                                            <div style="font-size: 0.7rem; color: #71717a;">Plan: {{ $inv->metadata['plan_name'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $inv->due_date?->format('d M, Y') ?? 'Immediate' }}
                                        @if ($inv->due_date && $inv->due_date->isPast())
                                            <span style="color: #e11d48; font-weight: 600; font-size: 0.7rem; margin-left: 0.25rem;">(Overdue)</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; font-family: monospace; font-weight: 700; font-size: 0.875rem;">
                                        ৳{{ number_format((float) $inv->total_amount, 2) }}
                                    </td>
                                    <td style="text-align: right;">
                                        @if ($walletBalance >= (float) $inv->total_amount)
                                            <x-filament::button
                                                color="success"
                                                size="xs"
                                                icon="heroicon-m-check-circle"
                                                wire:click="payWithWallet({{ $inv->id }})"
                                                wire:confirm="Deduct ৳{{ number_format((float) $inv->total_amount, 2) }} from your wallet balance to settle Invoice #{{ $inv->invoice_number }}?"
                                                wire:loading.attr="disabled"
                                            >
                                                Pay from Wallet
                                            </x-filament::button>
                                        @else
                                            <div style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <span style="font-size: 0.7rem; color: #71717a;">
                                                    Short by ৳{{ number_format((float) $inv->total_amount - $walletBalance, 2) }}
                                                </span>
                                                <x-filament::button
                                                    color="gray"
                                                    size="xs"
                                                    tag="a"
                                                    :href="route('filament.seller.resources.seller-wallets.index')"
                                                >
                                                    Top-up Wallet
                                                </x-filament::button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- 4. Available Marketplace Plans --}}
    <x-filament::section
        heading="Available Marketplace Plans"
        description="Upgrade your plan to unlock more product listing slots and lower sales commission rates."
    >
        <div class="b2b-plan-grid">
            @foreach ($plans as $p)
                @php
                    $isCurrent = ($subscription?->plan_id === $p->id);
                    $isFree = $p->isFree();
                @endphp
                <div class="b2b-plan-card {{ $isCurrent ? 'is-current' : '' }}">
                    <div>
                        {{-- Header & Badges --}}
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;">
                            <div>
                                <div style="font-size: 1.05rem; font-weight: 700; color: inherit;">
                                    {{ $p->name }}
                                </div>
                                @if ($p->description)
                                    <div style="font-size: 0.75rem; color: #71717a; margin-top: 0.2rem; line-height: 1.35;">
                                        {{ $p->description }}
                                    </div>
                                @endif
                            </div>

                            @if ($isCurrent)
                                <x-filament::badge color="success" size="xs">
                                    CURRENT
                                </x-filament::badge>
                            @elseif ($p->badge_label)
                                <x-filament::badge color="gray" size="xs">
                                    {{ strtoupper($p->badge_label) }}
                                </x-filament::badge>
                            @endif
                        </div>

                        {{-- Price Block --}}
                        <div style="margin-top: 1rem; padding-bottom: 0.875rem; border-bottom: 1px solid rgba(228, 228, 231, 0.6); display: flex; align-items: baseline; gap: 0.35rem;">
                            @if ($isFree)
                                <span style="font-size: 1.75rem; font-weight: 800; color: inherit;">Free</span>
                                <span style="font-size: 0.75rem; color: #71717a;">/ forever</span>
                            @else
                                <span style="font-size: 1.75rem; font-weight: 800; color: inherit;">৳{{ number_format((float) $p->subscription_price, 0) }}</span>
                                <span style="font-size: 0.75rem; color: #71717a;">/ {{ $p->billing_cycle_days }} days</span>
                            @endif
                        </div>

                        {{-- Features List --}}
                        <ul style="margin-top: 0.875rem; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.625rem; font-size: 0.75rem; color: #52525b;">
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <x-filament::icon icon="heroicon-o-cube" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span><strong>{{ $p->is_listing_limit_enabled ? number_format($p->max_active_products) . ' Active Listings' : 'Unlimited Listings' }}</strong></span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <x-filament::icon icon="heroicon-o-shopping-bag" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>Retail Commission: <strong style="color: inherit;">{{ $p->is_commission_enabled ? $p->retail_commission_rate . '%' : '0%' }}</strong></span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <x-filament::icon icon="heroicon-o-building-storefront" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>Wholesale Commission: <strong style="color: inherit;">{{ $p->is_commission_enabled ? $p->wholesale_commission_rate . '%' : '0%' }}</strong></span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>{{ $p->allow_listing_addons ? 'Listing Pack Add-ons Supported' : 'Fixed Product Quota' }}</span>
                            </li>
                        </ul>
                    </div>

                    {{-- Action Button --}}
                    <div style="margin-top: 1.25rem; padding-top: 0.875rem; border-top: 1px solid rgba(228, 228, 231, 0.6);">
                        @if ($isCurrent)
                            <x-filament::button color="gray" size="sm" disabled style="width: 100%;">
                                Active Plan
                            </x-filament::button>
                        @else
                            @if ($isFree)
                                <x-filament::button
                                    color="gray"
                                    size="sm"
                                    style="width: 100%;"
                                    wire:click="upgradePlan({{ $p->id }})"
                                    wire:confirm="Switch to {{ $p->name }} plan for free?"
                                    wire:loading.attr="disabled"
                                >
                                    Switch to {{ $p->name }}
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    color="primary"
                                    size="sm"
                                    style="width: 100%;"
                                    wire:click="upgradePlan({{ $p->id }})"
                                    wire:confirm="Generate an invoice of ৳{{ number_format((float) $p->subscription_price, 2) }} to upgrade to {{ $p->name }}?"
                                    wire:loading.attr="disabled"
                                >
                                    Upgrade to {{ $p->name }}
                                </x-filament::button>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- 5. Settled Billing History --}}
    @if ($paidInvoices->isNotEmpty())
        <div style="margin-top: 1.5rem;">
            <x-filament::section
                icon="heroicon-o-check-badge"
                icon-color="success"
                heading="Settled Billing History"
                description="Recent platform billing payments settled for your account."
            >
                <div style="overflow-x: auto;">
                    <table class="b2b-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Fee Type</th>
                                <th>Settled Date</th>
                                <th style="text-align: right;">Amount Paid (৳)</th>
                                <th style="text-align: right;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($paidInvoices as $paidInv)
                                <tr>
                                    <td style="font-family: monospace; font-weight: 600;">
                                        {{ $paidInv->invoice_number }}
                                    </td>
                                    <td style="text-transform: capitalize;">
                                        {{ str_replace('_', ' ', $paidInv->invoice_type) }}
                                    </td>
                                    <td>
                                        {{ $paidInv->paid_at?->format('d M, Y (h:i A)') ?? 'Settled' }}
                                    </td>
                                    <td style="text-align: right; font-family: monospace; font-weight: 700;">
                                        ৳{{ number_format((float) $paidInv->total_amount, 2) }}
                                    </td>
                                    <td style="text-align: right;">
                                        <x-filament::badge color="success" icon="heroicon-m-check">
                                            Paid
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
